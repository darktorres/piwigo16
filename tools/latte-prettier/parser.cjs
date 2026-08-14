"use strict";

// ---------------------------------------------------------------------------
// Latte template parser: hand-written recursive-descent parser with an
// integrated, contextually-moded scanner (no separate token-array pass).
//
// Produces a typed AST covering: HTML elements/attributes/text/comments,
// interleaved with real Latte constructs (if/elseif/else, foreach, var, do,
// spaceless, capture, include, comments, {$expr}/{=expr} output with filter
// chains), plus a small Latte expression grammar (variables with -> and []
// access, literals, unary/binary ops, positional+named-arg calls, filters).
// ---------------------------------------------------------------------------

const VOID_ELEMENTS = new Set([
  "area", "base", "br", "col", "embed", "hr", "img", "input",
  "link", "meta", "param", "source", "track", "wbr",
]);

const RAW_TEXT_ELEMENTS = new Set(["script", "style"]);

class ParseError extends Error {
  constructor(message, pos, text) {
    const { line, col } = offsetToLineCol(text, pos);
    super(`${message} (line ${line}, col ${col}, offset ${pos})`);
    this.pos = pos;
  }
}

function offsetToLineCol(text, pos) {
  let line = 1;
  let col = 1;
  for (let i = 0; i < pos && i < text.length; i++) {
    if (text[i] === "\n") {
      line++;
      col = 1;
    } else {
      col++;
    }
  }
  return { line, col };
}

class Scanner {
  constructor(text) {
    this.text = text;
    this.pos = 0;
    // Stack of lowercase names for HTML elements currently being parsed
    // (pushed before an element's children are parsed, popped right after) —
    // lets an unmatched closing tag be told apart from a real ancestor's
    // closer. See parseNodeList's stray-closing-tag handling.
    this.openTags = [];
  }
  eof() {
    return this.pos >= this.text.length;
  }
  peek(offset = 0) {
    return this.text[this.pos + offset];
  }
  startsWith(str) {
    return this.text.startsWith(str, this.pos);
  }
  startsWithCI(str) {
    return this.text.slice(this.pos, this.pos + str.length).toLowerCase() === str.toLowerCase();
  }
  advance(n = 1) {
    this.pos += n;
  }
  error(message) {
    throw new ParseError(message, this.pos, this.text);
  }
}

const isIdentStart = (ch) => ch !== undefined && /[A-Za-z_]/.test(ch);
const isIdentPart = (ch) => ch !== undefined && /[A-Za-z0-9_]/.test(ch);
const isSpace = (ch) => ch !== undefined && /[ \t\r\n]/.test(ch);
const isDigit = (ch) => ch !== undefined && /[0-9]/.test(ch);

function skipSpace(s) {
  while (!s.eof() && isSpace(s.peek())) s.advance();
}

function readIdentifier(s) {
  const start = s.pos;
  if (!isIdentStart(s.peek())) s.error("expected identifier");
  s.advance();
  while (isIdentPart(s.peek())) s.advance();
  return s.text.slice(start, s.pos);
}

// ---------------------------------------------------------------------------
// Latte tag-head lookahead: does `{` at s.pos start a real Latte tag?
// Mirrors real Latte's own `{...}` tag-delimiter lexer: a `{` counts as a
// tag attempt only when immediately (no space) followed by `$`, `=`, `*`,
// `/`+letter, or a letter. `{` followed by whitespace/digit/other punctuation
// is literal text (this is what makes `{capture}` CSS bodies like
// `.el{ width: ... }` safe, and matches the real compiler's own behavior
// confirmed against `{value: x}` JS-object-literal collisions).
// ---------------------------------------------------------------------------
function peekLatteHead(s) {
  if (s.peek() !== "{") return null;
  const src = s.text;
  let i = s.pos + 1;
  const c = src[i];
  if (c === "$") return { sigil: "$", bodyStart: i + 1 };
  if (c === "=") return { sigil: "=", bodyStart: i + 1 };
  if (c === "*") return { sigil: "*", bodyStart: i + 1 };
  // `(` starts a real tag too — a parenthesized expression is a valid bare
  // output with no `$`/`=` prefix, e.g. `{(cond) ? 'a' : 'b'}` (real Piwigo
  // markup, tags.latte). Unlike the other sigils, '(' is itself part of the
  // expression to reparse, so bodyStart doesn't skip past it.
  if (c === "(") return { sigil: "(", bodyStart: i };
  let closing = false;
  if (c === "/") {
    closing = true;
    i++;
  }
  if (!isIdentStart(src[i])) return null;
  const start = i;
  while (isIdentPart(src[i])) i++;
  const keyword = src.slice(start, i);
  return { keyword, closing, bodyStart: i };
}

function isRealLatteStart(head) {
  return head !== null;
}

function stopKey(head) {
  return (head.closing ? "/" : "") + (head.keyword || head.sigil);
}

// ---------------------------------------------------------------------------
// Latte tag-body scanning: find the matching unescaped `}` for a `{...}` tag,
// respecting single/double-quoted string literals with backslash escapes
// (Latte tags never nest braces outside of strings — calls/arrays use ()/[]).
// ---------------------------------------------------------------------------
function readTagBody(s) {
  // s.pos is at the first char of the tag body (after the sigil/keyword head)
  const start = s.pos;
  while (!s.eof()) {
    const ch = s.peek();
    if (ch === "'" || ch === '"') {
      s.advance();
      while (!s.eof() && s.peek() !== ch) {
        if (s.peek() === "\\") s.advance();
        s.advance();
      }
      if (s.eof()) s.error("unterminated string literal in Latte tag");
      s.advance();
      continue;
    }
    if (ch === "}") break;
    s.advance();
  }
  if (s.eof()) s.error("unterminated Latte tag, expected '}'");
  const body = s.text.slice(start, s.pos);
  s.advance(); // consume '}'
  return body;
}

function readCommentBody(s) {
  // s.pos is right after '{*'
  const start = s.pos;
  const idx = s.text.indexOf("*}", s.pos);
  if (idx === -1) s.error("unterminated {* comment *}");
  const body = s.text.slice(start, idx);
  s.pos = idx + 2;
  return body;
}

// ---------------------------------------------------------------------------
// Expression grammar (small, Latte-specific subset of PHP expressions)
// ---------------------------------------------------------------------------
class ExprScanner {
  constructor(text) {
    this.text = text;
    this.pos = 0;
  }
  eof() {
    return this.pos >= this.text.length;
  }
  peek(o = 0) {
    return this.text[this.pos + o];
  }
  advance(n = 1) {
    this.pos += n;
  }
  skipSpace() {
    while (!this.eof() && isSpace(this.peek())) this.advance();
  }
  error(message) {
    throw new Error(`${message} (in expression: ${JSON.stringify(this.text)}, at offset ${this.pos})`);
  }
  startsWithOp(op) {
    return this.text.startsWith(op, this.pos);
  }
}

const BINARY_OPS_BY_PRECEDENCE = [
  ["??"],
  ["||", "or"],
  ["&&", "and"],
  ["===", "!==", "==", "!=", "<=", ">=", "<", ">"],
  ["+", "-", "."],
  ["*", "/", "%"],
];

const CAST_TYPES = new Set(["string", "int", "integer", "float", "double", "bool", "boolean", "array", "object"]);

function parseExprString(text) {
  const es = new ExprScanner(text);
  es.skipSpace();
  const expr = parseFiltered(es);
  es.skipSpace();
  if (!es.eof()) es.error(`unexpected trailing content`);
  return expr;
}

// `cond ? then : else` — real Piwigo markup (tags.latte's bare
// `{(cond) ? 'a' : 'b'}`). No Elvis/short-ternary (`cond ?: else`) form
// observed, so not supported. Binds looser than `??` (parseBinary(0)) but
// tighter than filters, matching how the one real usage is written: the
// filter chain, if any, would apply to the ternary's overall result.
function parseTernary(es) {
  const cond = parseBinary(es, 0);
  es.skipSpace();
  if (es.peek() === "?" && es.peek(1) !== "?") {
    es.advance();
    es.skipSpace();
    const thenExpr = parseTernary(es);
    es.skipSpace();
    if (es.peek() !== ":") es.error("expected ':' in ternary expression");
    es.advance();
    es.skipSpace();
    const elseExpr = parseTernary(es);
    return { type: "Ternary", cond, then: thenExpr, else: elseExpr };
  }
  return cond;
}

function parseFiltered(es) {
  let expr = parseTernary(es);
  const filters = [];
  es.skipSpace();
  while (es.peek() === "|") {
    es.advance();
    es.skipSpace();
    const name = readExprIdentifier(es);
    let args = [];
    es.skipSpace();
    if (es.peek() === ":") {
      es.advance();
      args = parseArgExprList(es);
    }
    filters.push({ type: "FilterCall", name, args });
    es.skipSpace();
  }
  if (filters.length === 0) return expr;
  return { type: "Filtered", expr, filters };
}

function parseArgExprList(es) {
  const list = [];
  es.skipSpace();
  list.push(parseBinary(es, 0));
  es.skipSpace();
  while (es.peek() === ",") {
    es.advance();
    es.skipSpace();
    list.push(parseBinary(es, 0));
    es.skipSpace();
  }
  return list;
}

function parseBinary(es, level) {
  if (level >= BINARY_OPS_BY_PRECEDENCE.length) return parseUnary(es);
  let left = parseBinary(es, level + 1);
  es.skipSpace();
  for (;;) {
    const ops = BINARY_OPS_BY_PRECEDENCE[level];
    const op = ops.find((o) => {
      if (!es.startsWithOp(o)) return false;
      // word operators ('or'/'and') must not be a prefix of a longer identifier
      if (/^[A-Za-z]+$/.test(o)) {
        const next = es.text[es.pos + o.length];
        return !isIdentPart(next);
      }
      return true;
    });
    if (!op) break;
    es.advance(op.length);
    es.skipSpace();
    const right = parseBinary(es, level + 1);
    left = { type: "Binary", op, left, right };
    es.skipSpace();
  }
  return left;
}

function parseUnary(es) {
  es.skipSpace();
  if (es.peek() === "!") {
    es.advance();
    es.skipSpace();
    return { type: "Unary", op: "!", expr: parseUnary(es) };
  }
  if (es.peek() === "-" && !es.startsWithOp("--")) {
    es.advance();
    es.skipSpace();
    return { type: "Unary", op: "-", expr: parseUnary(es) };
  }
  if (es.peek() === "(") {
    // Try a PHP-style cast — `(string) $x`, `(int) $y` — before falling
    // back to a plain parenthesized expression, since both start with '('.
    const save = es.pos;
    es.advance();
    es.skipSpace();
    if (isIdentStart(es.peek())) {
      const typeName = readExprIdentifier(es);
      es.skipSpace();
      if (es.peek() === ")" && CAST_TYPES.has(typeName.toLowerCase())) {
        es.advance();
        es.skipSpace();
        return { type: "Cast", to: typeName, expr: parseUnary(es) };
      }
    }
    es.pos = save;
  }
  return parsePostfix(es);
}

function parsePostfix(es) {
  let expr = parsePrimary(es);
  for (;;) {
    if (es.peek() === "-" && es.peek(1) === ">") {
      es.advance(2);
      es.skipSpace();
      const name = readExprIdentifier(es);
      es.skipSpace();
      if (es.peek() === "(") {
        const args = parseCallArgs(es);
        expr = { type: "Call", callee: { type: "PropAccess", object: expr, prop: name }, args };
      } else {
        expr = { type: "PropAccess", object: expr, prop: name };
      }
      continue;
    }
    if (es.peek() === "[") {
      es.advance();
      es.skipSpace();
      const index = parseBinary(es, 0);
      es.skipSpace();
      if (es.peek() !== "]") es.error("expected ']'");
      es.advance();
      expr = { type: "Index", object: expr, index };
      continue;
    }
    if (es.peek() === "(" && expr.type === "Identifier") {
      const args = parseCallArgs(es);
      expr = { type: "Call", callee: expr, args };
      continue;
    }
    break;
  }
  return expr;
}

function parseCallArgs(es) {
  // es.peek() === '('
  es.advance();
  es.skipSpace();
  const args = [];
  if (es.peek() !== ")") {
    args.push(parseArg(es));
    es.skipSpace();
    while (es.peek() === ",") {
      es.advance();
      es.skipSpace();
      args.push(parseArg(es));
      es.skipSpace();
    }
  }
  if (es.peek() !== ")") es.error("expected ')'");
  es.advance();
  return args;
}

function parseArg(es) {
  const savedPos = es.pos;
  if (isIdentStart(es.peek())) {
    const name = readExprIdentifier(es);
    es.skipSpace();
    if (es.peek() === ":" && es.peek(1) !== ":") {
      es.advance();
      es.skipSpace();
      const value = parseFiltered(es);
      return { type: "Arg", name, value };
    }
    es.pos = savedPos;
  }
  const value = parseFiltered(es);
  return { type: "Arg", name: null, value };
}

function readExprIdentifier(es) {
  if (!isIdentStart(es.peek())) es.error("expected identifier");
  const start = es.pos;
  es.advance();
  while (isIdentPart(es.peek())) es.advance();
  return es.text.slice(start, es.pos);
}

function parsePrimary(es) {
  es.skipSpace();
  const ch = es.peek();
  if (ch === "$") {
    es.advance();
    const name = readExprIdentifier(es);
    return { type: "Variable", name };
  }
  if (ch === "'" || ch === '"') {
    const quote = ch;
    es.advance();
    const start = es.pos;
    while (!es.eof() && es.peek() !== quote) {
      if (es.peek() === "\\") es.advance();
      es.advance();
    }
    const value = es.text.slice(start, es.pos);
    if (es.peek() !== quote) es.error("unterminated string literal");
    es.advance();
    return { type: "StringLiteral", quote, value };
  }
  if (isDigit(ch)) {
    const start = es.pos;
    while (isDigit(es.peek())) es.advance();
    if (es.peek() === "." && isDigit(es.peek(1))) {
      es.advance();
      while (isDigit(es.peek())) es.advance();
    }
    return { type: "NumberLiteral", value: es.text.slice(start, es.pos) };
  }
  if (ch === "(") {
    es.advance();
    es.skipSpace();
    const expr = parseFiltered(es);
    es.skipSpace();
    if (es.peek() !== ")") es.error("expected ')'");
    es.advance();
    return { type: "Paren", expr };
  }
  if (ch === "[") {
    es.advance();
    es.skipSpace();
    const items = [];
    if (es.peek() !== "]") {
      items.push(parseBinary(es, 0));
      es.skipSpace();
      while (es.peek() === ",") {
        es.advance();
        es.skipSpace();
        if (es.peek() === "]") break; // trailing comma
        items.push(parseBinary(es, 0));
        es.skipSpace();
      }
    }
    if (es.peek() !== "]") es.error("expected ']'");
    es.advance();
    return { type: "ArrayLiteral", items };
  }
  if (isIdentStart(ch) || ch === "\\") {
    // Bare identifier, or a backslash-qualified global-namespace reference
    // like `\JSON_UNESCAPED_UNICODE` (real PHP, real Piwigo markup) — not
    // full PHP namespace resolution, just enough to round-trip this shape.
    const start = es.pos;
    if (ch === "\\") es.advance();
    readExprIdentifier(es);
    while (es.peek() === "\\") {
      es.advance();
      readExprIdentifier(es);
    }
    return { type: "Identifier", name: es.text.slice(start, es.pos) };
  }
  es.error(`unexpected character '${ch}' while parsing expression`);
}

// ---------------------------------------------------------------------------
// Node-list parser: shared by HTML content, attribute values, and capture
// bodies. `allowElements` gates whether `<tag>` markup is recognized (off for
// attribute values / capture bodies, which are plain text + Latte only).
// ---------------------------------------------------------------------------
function parseNodeList(s, opts) {
  const { allowElements, stopChar, stopKeywords } = opts;
  const nodes = [];
  for (;;) {
    if (s.eof()) break;
    if (stopChar && s.peek() === stopChar) break;
    if (allowElements && s.startsWith("</")) {
      // A closing tag whose name isn't anywhere in the current stack of open
      // elements can't belong to any ancestor *in this file*. Treating it
      // as "maybe belongs to an ancestor" (the default `break`, below)
      // unwinds every real ancestor's own unclosed-propagation logic one
      // level at a time until it reaches a body-loop boundary, flattening
      // everything in between out of its real nesting (found in
      // install.latte: a typo'd `</options>` — `<options>` is never opened
      // anywhere in the document — cascaded through td/tr/table/fieldset/
      // form). So: absorb it here instead, without propagating. What to do
      // with it once absorbed still depends on what it names, same as
      // before:
      //  - a void element (stray `<img ...></img>`, configuration_
      //    watermark.latte) never has a matching open/close pair to begin
      //    with, in any file — always safe garbage, drop it entirely.
      //  - anything else *might* be a real closing tag for an element
      //    opened in a *different* file, as part of a deliberate cross-file
      //    split (header.latte/footer.latte's `<div id="the_page">`) — this
      //    single-file parse can't tell "genuine typo" from "the other half
      //    is elsewhere", so preserve it as literal passthrough rather than
      //    guess it's garbage and silently delete a real closing tag.
      const strayName = peekCloseTagName(s);
      if (strayName !== null && !s.openTags.includes(strayName.toLowerCase())) {
        const orphan = parseOrphanCloseTag(s);
        if (!VOID_ELEMENTS.has(strayName.toLowerCase())) nodes.push(orphan);
        continue;
      }
      break; // caller decides: mine, an ancestor's, or an orphan
    }
    const head = peekLatteHead(s);
    if (isRealLatteStart(head)) {
      const key = stopKey(head);
      if (stopKeywords && stopKeywords.has(key)) break;
      nodes.push(parseLatteNode(s, head, opts));
      continue;
    }
    if (allowElements && s.peek() === "<") {
      nodes.push(parseHtmlAngle(s, stopKeywords));
      continue;
    }
    nodes.push(consumeTextRun(s, { allowElements, stopChar }));
  }
  return nodes;
}

// Loops parseNodeList, absorbing any closing tag it can't explain as literal
// passthrough instead of erroring. A "body" context (an {if} branch, a
// {foreach}/{spaceless} body, the top-level document) doesn't necessarily
// *own* every closing tag syntactically interleaved within it — real Piwigo
// markup overlaps HTML and Latte scopes (`<span>{spaceless}...{/if}</span>
// {/spaceless}`: the </span> belongs to a <span> opened *outside*
// {spaceless}'s own body, since Latte's real {spaceless} isn't HTML-aware at
// compile time — it's a pure text transform, not a balanced-tag construct).
// parseNodeList already stops (without consuming) at any unmatched `</...>`;
// this is what lets the loop tell "a real stopKeywords tag showed up" apart
// from "an ancestor's/orphan's closing tag showed up" and keep going for the
// latter, exactly like the top-level document already did for its own
// unclosed-fragment case (header.latte/footer.latte's split <div>).
function parseBodyLoop(s, allowElements, stopKeywords) {
  const nodes = [];
  for (;;) {
    nodes.push(...parseNodeList(s, { allowElements, stopKeywords }));
    if (s.eof()) return nodes;
    if (allowElements && s.startsWith("</")) {
      nodes.push(parseOrphanCloseTag(s));
      continue;
    }
    return nodes; // stopped at a real stopKeywords-matching tag; caller consumes it
  }
}

function consumeTextRun(s, { allowElements, stopChar }) {
  const start = s.pos;
  while (!s.eof()) {
    const ch = s.peek();
    if (stopChar && ch === stopChar) break;
    if (allowElements && ch === "<") break;
    if (ch === "{") {
      const head = peekLatteHead(s);
      if (isRealLatteStart(head)) break;
    }
    s.advance();
  }
  if (s.pos === start) s.error("internal error: empty text run");
  return { type: "HtmlText", value: s.text.slice(start, s.pos), start, end: s.pos };
}

function parseHtmlAngle(s, stopKeywords) {
  if (s.startsWith("<!--")) return parseHtmlComment(s);
  if (s.startsWithCI("<!doctype")) return parseDoctype(s);
  return parseElement(s, stopKeywords);
}

function parseHtmlComment(s) {
  const start = s.pos;
  const idx = s.text.indexOf("-->", s.pos + 4);
  if (idx === -1) s.error("unterminated HTML comment");
  s.pos = idx + 3;
  return { type: "HtmlComment", value: s.text.slice(start, s.pos), start, end: s.pos };
}

function parseDoctype(s) {
  const start = s.pos;
  const idx = s.text.indexOf(">", s.pos);
  if (idx === -1) s.error("unterminated <!DOCTYPE>");
  s.pos = idx + 1;
  return { type: "HtmlDoctype", value: s.text.slice(start, s.pos), start, end: s.pos };
}

// ---------------------------------------------------------------------------
// Element + attribute-list parsing. Attribute *lists* get the same
// leaf-vs-Latte-control-construct treatment as node lists, since real Piwigo
// markup conditionally renders whole attributes via {if}/{else} sitting
// directly among an element's attributes (not just inside one value).
// ---------------------------------------------------------------------------
function parseElement(s, inheritedStopKeywords) {
  const start = s.pos;
  s.advance(); // '<'
  const name = readIdentifier(s);
  const attributes = parseAttributeItems(s);
  skipSpace(s);
  let selfClosing = false;
  if (s.peek() === "/" && s.peek(1) === ">") {
    selfClosing = true;
    s.advance(2);
  } else if (s.peek() === ">") {
    s.advance();
  } else {
    s.error(`expected '>' or '/>' closing <${name}`);
  }
  const lower = name.toLowerCase();
  const isVoid = VOID_ELEMENTS.has(lower);
  let children = [];
  let rawText = false;
  let unclosed = false;
  let unclosedAtEof = false;
  if (!selfClosing && !isVoid) {
    if (RAW_TEXT_ELEMENTS.has(lower)) {
      rawText = true;
      const closeTag = `</${lower}`;
      const idxLower = s.text.toLowerCase().indexOf(closeTag, s.pos);
      if (idxLower === -1) s.error(`unterminated <${name}> (raw-text element)`);
      const rawValue = s.text.slice(s.pos, idxLower);
      children = rawValue.length ? [{ type: "HtmlText", value: rawValue, start: s.pos, end: idxLower }] : [];
      s.pos = idxLower;
    } else {
      // Forwarding the enclosing {if}/{foreach}/{spaceless} branch's own
      // stop keywords lets a deliberately-unclosed element nested inside it
      // (legacy HTML relying on implicit closing, e.g. a <td> never closed
      // before the next {else}) recognize that keyword as its own implicit
      // close instead of trying to parse it as a fresh, unrecognized tag.
      s.openTags.push(lower);
      children = parseNodeList(s, { allowElements: true, stopKeywords: inheritedStopKeywords });
      s.openTags.pop();
    }
    // A same-file document fragment (Piwigo's header/footer split) can leave
    // elements open at EOF, or close an element opened by a sibling file's
    // half of the same document — neither is an error here, just "unclosed".
    if (s.eof()) {
      unclosed = true;
      unclosedAtEof = true;
    } else {
      const closeName = peekCloseTagName(s);
      if (closeName !== null && closeName.toLowerCase() === lower) {
        s.advance(2);
        skipSpace(s);
        readIdentifier(s);
        skipSpace(s);
        if (s.peek() !== ">") s.error(`expected '>' to close </${name}>`);
        s.advance();
      } else {
        unclosed = true; // belongs to an ancestor (or is a top-level orphan) — don't consume
      }
    }
  }
  return {
    type: "HtmlElement",
    name,
    attributes,
    selfClosing,
    voidElement: isVoid,
    rawText,
    unclosed,
    unclosedAtEof,
    children,
    start,
    end: s.pos,
  };
}

// Lookahead only — does not mutate s.pos. Returns the tag name of a `</name`
// sequence at the current position, or null if not positioned at one.
function peekCloseTagName(s) {
  if (!s.startsWith("</")) return null;
  let i = s.pos + 2;
  const src = s.text;
  if (!isIdentStart(src[i])) return null;
  const start = i;
  while (isIdentPart(src[i])) i++;
  return src.slice(start, i);
}

function parseOrphanCloseTag(s) {
  const start = s.pos;
  s.advance(2);
  skipSpace(s);
  const name = readIdentifier(s);
  skipSpace(s);
  if (s.peek() !== ">") s.error(`expected '>' to close orphan </${name}>`);
  s.advance();
  return { type: "HtmlOrphanCloseTag", name, start, end: s.pos };
}

function parseAttributeItems(s, stopKeywords) {
  const items = [];
  for (;;) {
    skipSpace(s);
    if (s.eof()) break;
    if (s.peek() === "/" && s.peek(1) === ">") break;
    if (s.peek() === ">") break;
    const head = peekLatteHead(s);
    if (isRealLatteStart(head)) {
      if (stopKeywords && stopKeywords.has(stopKey(head))) break;
      items.push(parseLatteNode(s, head, { isAttrList: true }));
      continue;
    }
    items.push(parseOneAttribute(s));
  }
  return items;
}

// Like parseNodeList(s, {allowElements:false}) but bounded by whitespace/
// '>'/'/' instead of a single stopChar (there's no quote to look for).
function parseUnquotedAttrValue(s) {
  const nodes = [];
  for (;;) {
    if (s.eof() || isSpace(s.peek()) || s.peek() === ">" || s.peek() === "/") break;
    const head = peekLatteHead(s);
    if (isRealLatteStart(head)) {
      nodes.push(parseLatteNode(s, head, { allowElements: false }));
      continue;
    }
    const start = s.pos;
    while (!s.eof() && !isSpace(s.peek()) && s.peek() !== ">" && s.peek() !== "/") {
      if (isRealLatteStart(peekLatteHead(s))) break;
      s.advance();
    }
    nodes.push({ type: "HtmlText", value: s.text.slice(start, s.pos), start, end: s.pos });
  }
  return nodes;
}

function parseOneAttribute(s) {
  const start = s.pos;
  if (!isIdentStart(s.peek()) && s.peek() !== "-" && s.peek() !== ":") {
    s.error(`unexpected character '${s.peek()}' in attribute list`);
  }
  const nameStart = s.pos;
  while (!s.eof() && /[A-Za-z0-9_:.-]/.test(s.peek())) s.advance();
  const name = s.text.slice(nameStart, s.pos);
  let hasValue = false;
  let quote = null;
  let value = null;
  const save = s.pos;
  skipSpace(s);
  if (s.peek() === "=") {
    s.advance();
    skipSpace(s);
    hasValue = true;
    const q = s.peek();
    if (q === '"' || q === "'") {
      quote = q;
      s.advance();
      value = parseNodeList(s, { allowElements: false, stopChar: q });
      if (s.peek() !== q) s.error(`unterminated attribute value for "${name}"`);
      s.advance();
    } else {
      // Unquoted attribute value (e.g. `id={$key}`) — real, live Piwigo
      // markup, confirmed via the AST-equivalence test catching a silent
      // corruption here: a first cut at this treated the whole span as
      // literal text, which silently turned a real `{$key}` substitution
      // into the 7 literal characters "{$key}" once reprinted and
      // re-parsed. Needs the same Latte-tag-aware scanning as a quoted
      // value, just bounded by whitespace/`>`/`/` instead of a quote char.
      value = parseUnquotedAttrValue(s);
    }
  } else {
    s.pos = save;
  }
  return {
    type: "Attribute",
    name,
    hasValue,
    quote,
    value,
    start,
    end: s.pos,
  };
}

// ---------------------------------------------------------------------------
// Latte construct dispatch
// ---------------------------------------------------------------------------
function parseLatteNode(s, head, listOpts) {
  const start = s.pos;
  s.advance(); // '{'
  if (head.sigil === "*") {
    s.advance(); // consume the '*' sigil itself (readCommentBody starts right after it)
    const body = readCommentBody(s);
    return { type: "LatteComment", value: body, start, end: s.pos };
  }
  if (head.sigil === "$" || head.sigil === "=") {
    const body = readTagBody(s);
    const expr = parseExprString(head.sigil === "=" ? body.replace(/^=/, "") : body);
    return { type: "LatteOutput", form: head.sigil, expr, start, end: s.pos };
  }
  if (head.sigil === "(") {
    const body = readTagBody(s);
    const expr = parseExprString(body);
    return { type: "LatteOutput", form: "bare", expr, start, end: s.pos };
  }
  if (head.closing) {
    s.error(`unexpected closing tag {/${head.keyword}}`);
  }
  switch (head.keyword) {
    case "if":
      return parseIf(s, start, listOpts);
    case "foreach":
      return parseForeach(s, start, listOpts);
    case "for":
      return parseFor(s, start, listOpts);
    case "var":
      return parseVar(s, start);
    case "do":
      return parseDo(s, start);
    case "breakIf":
      return parseBreakIf(s, start);
    case "contentType":
      return parseContentType(s, start);
    case "varType":
      return parseVarType(s, start);
    case "spaceless":
      return parseSpaceless(s, start, listOpts);
    case "capture":
      return parseCapture(s, start);
    case "include":
      return parseInclude(s, start);
    case "define":
      return parseDefine(s, start, listOpts);
    default:
      // A bare `{funcName(...)}` (no leading `$`/`=`) with no recognized
      // control-tag keyword is real, valid Piwigo Latte markup for an
      // implicit output expression — e.g. `{count($TAGS_FOUND)}`, the same
      // as writing `{=count($TAGS_FOUND)}`. Only treat it that way when
      // it's unambiguously a call (keyword immediately followed by '('),
      // not an actually-unrecognized control tag.
      if (s.text[head.bodyStart] === "(") {
        const body = readTagBody(s);
        const expr = parseExprString(body);
        return { type: "LatteOutput", form: "bare", expr, start, end: s.pos };
      }
      s.error(`unknown Latte tag {${head.keyword}}`);
  }
}

function parseIf(s, start, listOpts) {
  const condSrc = readTagBody(s).replace(/^if\s+/, "");
  const cond = parseExprString(condSrc);
  const isAttrList = Boolean(listOpts && listOpts.isAttrList);

  const ifStops = new Set(["elseif", "else", "/if"]);
  const branchOf = () =>
    isAttrList
      ? parseAttributeItems(s, ifStops)
      : parseBodyLoop(s, Boolean(listOpts && listOpts.allowElements), ifStops);

  const consequent = branchOf();
  const elseifs = [];
  let alternate = null;
  for (;;) {
    const head = peekLatteHead(s);
    if (!head) s.error("expected {elseif}, {else}, or {/if}");
    const key = stopKey(head);
    if (key === "elseif") {
      s.advance(); // '{'
      const src = readTagBody(s).replace(/^elseif\s+/, "");
      const c = parseExprString(src);
      const body = branchOf();
      elseifs.push({ cond: c, body });
      continue;
    }
    if (key === "else") {
      s.advance();
      readTagBody(s); // consume '{else}'
      alternate = branchOf();
      continue;
    }
    if (key === "/if") {
      s.advance();
      readTagBody(s); // consume '{/if}'
      break;
    }
    s.error(`unexpected {${key}} inside {if} construct`);
  }
  return { type: "LatteIf", cond, consequent, elseifs, alternate, start, end: s.pos };
}

function parseForeach(s, start, listOpts) {
  const src = readTagBody(s).replace(/^foreach\s+/, "");
  const m = /^(.*?)\s+as\s+(.+)$/s.exec(src);
  if (!m) s.error(`malformed {foreach ${src}}: expected "EXPR as $var" or "EXPR as $k => $v"`);
  const iterable = parseExprString(m[1]);
  let keyVar = null;
  let valueVar;
  const asPart = m[2].trim();
  const arrowIdx = asPart.indexOf("=>");
  if (arrowIdx !== -1) {
    keyVar = parseExprString(asPart.slice(0, arrowIdx).trim());
    valueVar = parseExprString(asPart.slice(arrowIdx + 2).trim());
  } else {
    valueVar = parseExprString(asPart);
  }
  const isAttrList = Boolean(listOpts && listOpts.isAttrList);
  const foreachStops = new Set(["/foreach"]);
  const body = isAttrList
    ? parseAttributeItems(s, foreachStops)
    : parseBodyLoop(s, Boolean(listOpts && listOpts.allowElements), foreachStops);
  const head = peekLatteHead(s);
  if (!head || stopKey(head) !== "/foreach") s.error("expected {/foreach}");
  s.advance();
  readTagBody(s);
  return { type: "LatteForeach", iterable, keyVar, valueVar, body, start, end: s.pos };
}

// A tiny statement grammar (assignment, post-increment/decrement) on top of
// the expression grammar — used by {for}'s INIT/STEP clauses (`{for $day =
// 1; $day <= 32; $day++}`) and by {do}, which can also carry an assignment
// with side effects (`{do $all_selected_album[$element['ID']] =
// json_decode(...)}`, real Piwigo markup). Not allowed in a general
// expression position, only here — same restriction real PHP/Latte apply.
// The target can be any postfix chain (`$x`, `$x[$k]`, `$x->prop`), not
// just a bare variable, so it's parsed with parsePostfix, not a plain
// identifier read.
function parseStatement(text) {
  const es = new ExprScanner(text);
  es.skipSpace();
  if (es.peek() === "$") {
    const save = es.pos;
    const target = parsePostfix(es);
    es.skipSpace();
    if (es.startsWithOp("++") || es.startsWithOp("--")) {
      const op = es.text.slice(es.pos, es.pos + 2);
      es.advance(2);
      return { type: "PostIncDec", op, target };
    }
    const twoCharAssignOps = ["+=", "-=", "*=", "/="];
    let op = twoCharAssignOps.find((o) => es.startsWithOp(o));
    if (!op && es.peek() === "=" && es.peek(1) !== "=") op = "=";
    if (op) {
      es.advance(op.length);
      es.skipSpace();
      const value = parseFiltered(es);
      return { type: "Assignment", op, target, value };
    }
    es.pos = save;
  }
  const expr = parseFiltered(es);
  es.skipSpace();
  if (!es.eof()) es.error("unexpected trailing content");
  return expr;
}

function parseFor(s, start, listOpts) {
  const src = readTagBody(s).replace(/^for\s+/, "");
  const clauses = splitTopLevel(src, ";");
  if (clauses.length !== 3) s.error(`malformed {for ${src}}: expected "INIT; COND; STEP"`);
  const init = parseStatement(clauses[0]);
  const cond = parseExprString(clauses[1].trim());
  const step = parseStatement(clauses[2]);
  const isAttrList = Boolean(listOpts && listOpts.isAttrList);
  const forStops = new Set(["/for"]);
  const body = isAttrList
    ? parseAttributeItems(s, forStops)
    : parseBodyLoop(s, Boolean(listOpts && listOpts.allowElements), forStops);
  const head = peekLatteHead(s);
  if (!head || stopKey(head) !== "/for") s.error("expected {/for}");
  s.advance();
  readTagBody(s);
  return { type: "LatteFor", init, cond, step, body, start, end: s.pos };
}

function parseVar(s, start) {
  const src = readTagBody(s).replace(/^var\s+/, "");
  const eq = src.indexOf("=");
  if (eq === -1) s.error(`malformed {var ${src}}: expected "$name = expr"`);
  const nameSrc = src.slice(0, eq).trim();
  if (!nameSrc.startsWith("$")) s.error(`malformed {var}: expected variable name, got "${nameSrc}"`);
  const name = nameSrc.slice(1);
  const value = parseExprString(src.slice(eq + 1).trim());
  return { type: "LatteVar", name, value, start, end: s.pos };
}

function parseBreakIf(s, start) {
  const src = readTagBody(s).replace(/^breakIf\s+/, "");
  const cond = parseExprString(src);
  return { type: "LatteBreakIf", cond, start, end: s.pos };
}

// `{contentType text}` — a template-header pragma declaring the output
// content type (real Piwigo markup: mail/text/plain/*.latte's plain-text
// email templates). Always a bare identifier, not a general expression.
function parseContentType(s, start) {
  const src = readTagBody(s).replace(/^contentType\s+/, "");
  const value = src.trim();
  return { type: "LatteContentType", value, start, end: s.pos };
}

// Like readTagBody(), but tracks `{`/`}` nesting depth instead of stopping
// at the first `}` -- PHPStan array-shape syntax (`array{key: type, ...}`),
// routine in real {varType} content, embeds literal braces of its own that
// a first-`}`-wins scan would mistake for the tag's own close, silently
// truncating mid-type and leaving the remainder (e.g. a second `array{...}`
// union member) to be mis-parsed as ordinary template text -- confirmed
// live: `{CURRENT_PAGE?: float, ...}` inside an array-shape's own braces
// read back as an attempted new Latte tag once truncation split it out.
function readNestedBracedTagBody(s) {
  const start = s.pos;
  let depth = 0;
  while (!s.eof()) {
    const ch = s.peek();
    if (ch === "'" || ch === '"') {
      s.advance();
      while (!s.eof() && s.peek() !== ch) {
        if (s.peek() === "\\") s.advance();
        s.advance();
      }
      if (s.eof()) s.error("unterminated string literal in Latte tag");
      s.advance();
      continue;
    }
    if (ch === "{") {
      depth++;
    } else if (ch === "}") {
      if (depth === 0) break;
      depth--;
    }
    s.advance();
  }
  if (s.eof()) s.error("unterminated Latte tag, expected '}'");
  const body = s.text.slice(start, s.pos);
  s.advance(); // consume '}'
  return body;
}

// `{varType Type $name}` — an IDE-autocompletion-only declaration (compiles
// to a literal empty string, see vendor/latte/latte's own VarTypeNode.php).
// The "Type" half is real PHP/PHPStan type syntax (FQCN, unions, nullable,
// generics via TagParser::parseType()'s SuperiorTypeNode), not a general
// expression -- same reasoning as {contentType}, kept as a raw string
// rather than parsed, since nothing here needs to inspect the type itself.
function parseVarType(s, start) {
  const src = readNestedBracedTagBody(s).replace(/^varType\s+/, "");
  const value = src.trim();
  return { type: "LatteVarType", value, start, end: s.pos };
}

function parseDo(s, start) {
  const src = readTagBody(s).replace(/^do\s+/, "");
  const expr = parseStatement(src);
  return { type: "LatteDo", expr, start, end: s.pos };
}

function parseSpaceless(s, start, listOpts) {
  readTagBody(s); // consume '{spaceless}'
  const isAttrList = Boolean(listOpts && listOpts.isAttrList);
  const spacelessStops = new Set(["/spaceless"]);
  const body = isAttrList
    ? parseAttributeItems(s, spacelessStops)
    : parseBodyLoop(s, Boolean(listOpts && listOpts.allowElements), spacelessStops);
  const head = peekLatteHead(s);
  if (!head || stopKey(head) !== "/spaceless") s.error("expected {/spaceless}");
  s.advance();
  readTagBody(s);
  return { type: "LatteSpaceless", body, start, end: s.pos };
}

// `{define name}...{/define}` — a named, reusable template fragment,
// invoked later via `{include name, arg: val, ...}` (already-supported
// syntax — {define} itself takes no parameter list, only a bare name).
function parseDefine(s, start, listOpts) {
  const src = readTagBody(s).replace(/^define\s+/, "");
  const name = src.trim();
  const isAttrList = Boolean(listOpts && listOpts.isAttrList);
  const defineStops = new Set(["/define"]);
  const body = isAttrList
    ? parseAttributeItems(s, defineStops)
    : parseBodyLoop(s, Boolean(listOpts && listOpts.allowElements), defineStops);
  const head = peekLatteHead(s);
  if (!head || stopKey(head) !== "/define") s.error("expected {/define}");
  s.advance();
  readTagBody(s);
  return { type: "LatteDefine", name, body, start, end: s.pos };
}

function parseCapture(s, start) {
  const src = readTagBody(s).replace(/^capture\s+/, "");
  if (!src.startsWith("$")) s.error(`malformed {capture ${src}}: expected a variable name`);
  const varName = src.slice(1).trim();
  const body = parseNodeList(s, { allowElements: false, stopKeywords: new Set(["/capture"]) });
  const head = peekLatteHead(s);
  if (!head || stopKey(head) !== "/capture") s.error("expected {/capture}");
  s.advance();
  readTagBody(s);
  return { type: "LatteCapture", varName, body, start, end: s.pos };
}

function parseInclude(s, start) {
  const src = readTagBody(s).replace(/^include\s+/, "");
  const parts = splitTopLevel(src, ",");
  const target = parseExprString(parts[0]);
  const args = parts.slice(1).map((p) => parseArg(new ExprScanner(p.trim())));
  return { type: "LatteInclude", target, args, start, end: s.pos };
}

// Splits on a top-level separator char only (not inside quotes/brackets/
// parens) — used for {include}'s "target, arg, arg" argument tail (','),
// and {for}'s "init; cond; step" clauses (';').
function splitTopLevel(src, sep) {
  const parts = [];
  let depth = 0;
  let cur = "";
  for (let i = 0; i < src.length; i++) {
    const ch = src[i];
    if (ch === "'" || ch === '"') {
      const quote = ch;
      cur += ch;
      i++;
      while (i < src.length && src[i] !== quote) {
        if (src[i] === "\\") {
          cur += src[i];
          i++;
        }
        cur += src[i];
        i++;
      }
      cur += src[i];
      continue;
    }
    if (ch === "(" || ch === "[") depth++;
    if (ch === ")" || ch === "]") depth--;
    if (ch === sep && depth === 0) {
      parts.push(cur);
      cur = "";
      continue;
    }
    cur += ch;
  }
  parts.push(cur);
  return parts;
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------
function parse(text) {
  const s = new Scanner(text);
  // Empty stopKeywords: the top level has no real terminator but EOF, so
  // parseBodyLoop's other branch (absorb an unowned closing tag as an
  // orphan and keep going) handles the header.latte/footer.latte
  // split-fragment case — e.g. footer.latte closing a <div> that
  // header.latte opened — the same way it now does at every nested level.
  const children = parseBodyLoop(s, true, new Set());
  return { type: "Document", children, start: 0, end: text.length };
}

module.exports = {
  parse,
  parseExprString,
  ParseError,
};
