"use strict";

const prettier = require("prettier");
const { group, indent, join, line, softline, hardline } = prettier.doc.builders;

// ---------------------------------------------------------------------------
// Expression printer (Latte expression AST -> Prettier Doc). Canonical
// spacing (e.g. `a + b`, `name: value`) is applied deliberately — the plan's
// verification bar is structural fidelity, not byte-identical text, and real
// Prettier plugins normalize operator/argument spacing the same way.
// ---------------------------------------------------------------------------
function exprToDoc(e) {
  switch (e.type) {
    case "Variable":
      return "$" + e.name;
    case "StringLiteral":
      return e.quote + e.value + e.quote;
    case "NumberLiteral":
      return e.value;
    case "Identifier":
      return e.name;
    case "Paren":
      return ["(", exprToDoc(e.expr), ")"];
    case "Unary":
      return [e.op, exprToDoc(e.expr)];
    case "Cast":
      return ["(", e.to, ") ", exprToDoc(e.expr)];
    case "ArrayLiteral":
      return ["[", join(", ", e.items.map(exprToDoc)), "]"];
    case "Assignment":
      return [exprToDoc(e.target), " ", e.op, " ", exprToDoc(e.value)];
    case "PostIncDec":
      return [exprToDoc(e.target), e.op];
    case "Ternary":
      return [
        exprToDoc(e.cond),
        " ? ",
        exprToDoc(e.then),
        " : ",
        exprToDoc(e.else),
      ];
    case "Binary":
      return [exprToDoc(e.left), " ", e.op, " ", exprToDoc(e.right)];
    case "PropAccess":
      return [exprToDoc(e.object), e.nullsafe ? "?->" : "->", e.prop];
    case "StaticAccess":
      return [exprToDoc(e.object), "::", e.prop];
    case "Index":
      return [exprToDoc(e.object), "[", exprToDoc(e.index), "]"];
    case "Call": {
      // Latte tag bodies are never allowed to wrap onto their own lines —
      // an expression can sit inside a quoted HTML attribute value or
      // otherwise-inline content, where a literal line break would corrupt
      // the surrounding markup. Args always print on a single line.
      if (e.args.length === 0) return [exprToDoc(e.callee), "()"];
      const argDocs = e.args.map(printArg);
      return [exprToDoc(e.callee), "(", join(", ", argDocs), ")"];
    }
    case "Filtered": {
      const parts = [exprToDoc(e.expr)];
      for (const f of e.filters) {
        parts.push("|", f.name);
        if (f.args.length) parts.push(":", join(",", f.args.map(exprToDoc)));
      }
      return parts;
    }
    default:
      throw new Error(`printer: unknown expression node type '${e.type}'`);
  }
}

function printArg(a) {
  return a.name ? [a.name, ": ", exprToDoc(a.value)] : exprToDoc(a.value);
}

function varsDoc(node) {
  return node.keyVar
    ? [exprToDoc(node.keyVar), " => ", exprToDoc(node.valueVar)]
    : exprToDoc(node.valueVar);
}

// ---------------------------------------------------------------------------
// Content-list handling shared by HTML element/document bodies, {if}/
// {foreach}/{spaceless} branches. A maximal run of literal characters
// between tags is one HtmlText node; here it's split into leading/trailing
// whitespace (folded into the gap before/after) and a trimmed "core" (its
// internal whitespace runs collapsed to a single space). Gaps between
// adjacent real items are re-emitted based on the ORIGINAL newline count
// between them: 0 -> single space, 1 -> hardline, 2+ -> one blank line —
// real reformatting (fixes indentation, collapses runaway blank lines)
// without guessing at inline/block semantics per element type.
// ---------------------------------------------------------------------------
function buildContentSequence(rawNodes) {
  const items = [];
  let pendingGap = "";
  for (const n of rawNodes) {
    if (n.type === "HtmlText") {
      const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(n.value);
      const lead = m[1];
      const core = m[2];
      const trail = m[3];
      pendingGap += lead;
      if (core.length) {
        items.push({ kind: "text", core, gapBefore: pendingGap });
        pendingGap = trail;
      }
      continue;
    }
    items.push({ kind: "node", node: n, gapBefore: pendingGap });
    pendingGap = "";
  }
  return { items, trailingGap: pendingGap };
}

// `raw`: true inside a {contentType text} document, where gaps are literal
// recipient-visible output (real Latte only auto-trims a line that is
// *purely* whitespace + a control tag; a line like `  {$var}` renders its
// two leading spaces verbatim) rather than source-code indentation safely
// re-derived from nesting depth. Printing the original gap string outright
// keeps it byte-exact instead of recomputing it from the current indent().
function gapDoc(gap, raw) {
  if (!gap) return "";
  if (raw) return gap;
  const nl = (gap.match(/\n/g) || []).length;
  if (nl === 0) return " ";
  if (nl === 1) return hardline;
  return [hardline, hardline];
}

// Collapses internal whitespace runs to a single space. Deliberately never
// gets its own wrap points: a `fill()` over word/line pairs looked appealing
// for long prose, but on short trailing fragments (e.g. a lone ") -" between
// two Latte tags) it would just as happily break between two tokens that
// have no business being split, dangling a stray "-" on its own line.
// `raw`: see gapDoc — leaves the text fully untouched.
function textFillDoc(core, raw) {
  if (raw) return core;
  return core.split(/\s+/).filter(Boolean).join(" ");
}

// Returns null for an empty (whitespace-only-or-empty) body, else
// {leadBreak, inner, trailBreak} for the caller to wrap with indent().
function computeBlock(nodes, options) {
  const raw = Boolean(options.__latteContentTypeText);
  const { items, trailingGap } = buildContentSequence(nodes);
  if (items.length === 0) return null;
  const inner = [];
  for (let i = 0; i < items.length; i++) {
    if (i > 0) inner.push(gapDoc(items[i].gapBefore, raw));
    inner.push(
      items[i].kind === "text"
        ? textFillDoc(items[i].core, raw)
        : printNode(items[i].node, options, "block"),
    );
  }
  const lead = items[0].gapBefore;
  const leadBreak = raw
    ? lead
    : lead.includes("\n")
      ? hardline
      : lead
        ? " "
        : "";
  const trailBreak = raw
    ? trailingGap
    : trailingGap.includes("\n")
      ? hardline
      : trailingGap
        ? " "
        : "";
  return { leadBreak, inner, trailBreak };
}

// Inline-only rendering (attribute values, {capture} bodies): text stays
// byte-verbatim (no fill/wrap — a stray line-break would corrupt a quoted
// HTML attribute or raw CSS/JS), nested Latte constructs recurse in the same
// mode with no hardline/indent machinery. {capture} bodies specifically are
// raw CSS/JS passthrough — a real lexer-state hazard is already documented
// for this exact corpus (nested `{` braces of the captured language must not
// be touched), so the text is preserved fully byte-verbatim rather than
// reformatted. (An earlier version re-emitted the body's literal newlines as
// `hardline` purely to re-indent it to the surrounding structure's level;
// that broke idempotency — a second format pass saw the previous pass's
// inserted indentation as part of the literal text and added *another*
// layer on top of it, growing without bound.)
function printInlinePart(n, options) {
  if (n.type === "HtmlText") return n.value;
  return printNode(n, options, "inline");
}

// ---------------------------------------------------------------------------
// Attribute-list printing. Real Piwigo markup conditionally renders whole
// *attributes* via {if}/{else} sitting directly among an element's
// attributes (not just inside one value) — attrPieces flattens that into a
// single space/line-joined sequence alongside plain attributes.
// ---------------------------------------------------------------------------
function printPlainAttribute(a, options) {
  if (!a.hasValue) return a.name;
  const valueParts = a.value.map((v) =>
    v.type === "HtmlText" ? v.value : printNode(v, options, "inline"),
  );
  const q = a.quote || '"';
  return [a.name, "=", q, valueParts, q];
}

const SIMPLE_ATTR_PASSTHROUGH = new Set([
  "LatteOutput",
  "LatteComment",
  "LatteVar",
  "LatteDo",
  "LatteBreakIf",
  "LatteFor",
  "LatteDefine",
  "LatteBlock",
]);

function attrPieces(item, options) {
  if (item.type === "Attribute") return [printPlainAttribute(item, options)];
  // parseAttributeItems() dispatches any real Latte tag generically, so a
  // bare {$expr}/{=expr}, {* comment *}, {var}, {do}, {breakIf}, {for}, or
  // {define} can sit directly among attributes too, not just {if}/
  // {foreach}. printNode's 'inline' mode handles all of these correctly
  // (the block-bodied ones just print their body inline, same as anywhere
  // else a construct like this shows up in an attribute value).
  if (SIMPLE_ATTR_PASSTHROUGH.has(item.type)) {
    return [printNode(item, options, "inline")];
  }
  if (item.type === "LatteIf") {
    const out = [["{if ", exprToDoc(item.cond), "}"]];
    for (const it of item.consequent) out.push(...attrPieces(it, options));
    for (const ei of item.elseifs) {
      out.push(["{elseif ", exprToDoc(ei.cond), "}"]);
      for (const it of ei.body) out.push(...attrPieces(it, options));
    }
    if (item.alternate) {
      out.push("{else}");
      for (const it of item.alternate) out.push(...attrPieces(it, options));
    }
    out.push("{/if}");
    return out;
  }
  if (item.type === "LatteForeach") {
    const out = [
      ["{foreach ", exprToDoc(item.iterable), " as ", varsDoc(item), "}"],
    ];
    for (const it of item.body) out.push(...attrPieces(it, options));
    out.push("{/foreach}");
    return out;
  }
  throw new Error(
    `printer: unexpected attribute-list item type '${item.type}'`,
  );
}

function printOpenTag(node, options) {
  if (!node.attributes.length) {
    return ["<", node.name, node.selfClosing ? " />" : ">"];
  }
  const pieces = [];
  for (const a of node.attributes) pieces.push(...attrPieces(a, options));
  return group([
    "<",
    node.name,
    indent(pieces.map((p) => [line, p])),
    node.selfClosing ? line : softline,
    node.selfClosing ? "/>" : ">",
  ]);
}

// ---------------------------------------------------------------------------
// Main node dispatch. `mode` is 'block' (default; HTML body context — may
// use hardline/indent) or 'inline' (attribute values / capture bodies — must
// stay single-line-safe). HtmlElement/HtmlText/Document only ever occur in
// 'block' contexts by construction (attribute values and capture bodies
// never parse elements), so they don't need to branch on mode.
// ---------------------------------------------------------------------------
function printNode(node, options, mode = "block") {
  switch (node.type) {
    case "Document": {
      // A document with no real HTML elements after some leading tags --
      // e.g. themes/default/template/mail/text/html/global-mail-css.latte
      // (a {varType} block followed by raw CSS meant to be dropped verbatim
      // into a sibling file's <style> block via {$GLOBAL_MAIL_CSS|noescape})
      // -- has no Latte-tag/element boundaries within that trailing run for
      // the usual gap-based item reformatting below to work from: it's one
      // continuous HtmlText run, so it would go through textFillDoc and have
      // every deliberate line break in hand-formatted multi-line CSS
      // collapsed into a single unreadable line. Preserve that trailing run
      // byte-verbatim instead (just trimmed of the outer whitespace the
      // trailing hardline below already accounts for); any leading non-text
      // nodes (originally none -- now always at least the generated
      // {varType} block) print normally through the standard path below.
      // Every document has one -- when a template has no other Latte tags
      // to serve as the split point, all of children is that leading run
      // (lastNonTextIdx === -1) and this reduces to "verbatim from byte 0",
      // the same behavior this case originally covered.
      let lastNonTextIdx = -1;
      for (let i = 0; i < node.children.length; i++) {
        if (node.children[i].type !== "HtmlText") lastNonTextIdx = i;
      }
      if (lastNonTextIdx < node.children.length - 1) {
        const leading = node.children.slice(0, lastNonTextIdx + 1);
        const trailingRawFull = node.children
          .slice(lastNonTextIdx + 1)
          .map((c) => c.value)
          .join("");
        // No leading part: trim() both ends, exactly the original all-text
        // behavior this case still covers. A leading part: trimEnd() only
        // -- the leading gap (e.g. the newline between a leading tag and
        // this run) is part of what "byte-verbatim" promises to preserve
        // untouched, same as the rest of this text; only the trailing
        // whitespace needs normalizing, since the hardline below already
        // guarantees "file ends in exactly one newline".
        const trailingRaw = leading.length
          ? trailingRawFull.trimEnd()
          : trailingRawFull.trim();
        if (trailingRaw.trim()) {
          const leadingDoc = leading.length
            ? printNode({ ...node, children: leading }, options, mode).slice(
                0,
                -1,
              )
            : [];
          return [...leadingDoc, trailingRaw, hardline];
        }
      }
      // {contentType text} (mail/text/plain/*.latte) declares this template's
      // *output* is the literal email body, not source code -- unlike every
      // other template, its whitespace isn't ours to renormalize by nesting
      // depth. Sets a flag every descendant print call sees (they all share
      // this same `options` object), so a {$var}/text line's original
      // indentation survives even nested inside {if}/{foreach} bodies. See
      // gapDoc's `raw` param for why this is safe to do unconditionally: a
      // control-tag-only line still gets auto-trimmed by real Latte
      // regardless of what indentation we print before it.
      if (
        node.children.some(
          (c) => c.type === "LatteContentType" && c.value === "text",
        )
      ) {
        options.__latteContentTypeText = true;
      }
      const raw = Boolean(options.__latteContentTypeText);
      const { items } = buildContentSequence(node.children);
      const parts = [];
      for (let i = 0; i < items.length; i++) {
        if (i > 0) parts.push(gapDoc(items[i].gapBefore, raw));
        parts.push(
          items[i].kind === "text"
            ? textFillDoc(items[i].core, raw)
            : printNode(items[i].node, options, "block"),
        );
      }
      // The one place responsible for "the file ends in exactly one
      // newline" — deliberately not delegated to a descendant's own
      // trailing-gap logic (see the unclosedAtEof note below), so it can't
      // double-count with one.
      parts.push(hardline);
      return parts;
    }

    case "HtmlElement": {
      const openTag = printOpenTag(node, options);
      if (node.voidElement) return openTag;
      const closeTag = node.unclosed ? "" : ["</", node.name, ">"];
      if (node.rawText) {
        const raw = node.children.length ? node.children[0].value : "";
        return [openTag, raw, closeTag];
      }
      const b = computeBlock(node.children, options);
      if (!b) return [openTag, closeTag];
      // An element left open because parsing simply ran out of file (the
      // header.latte/footer.latte split-fragment case, or this file's own
      // deliberately-unclosed mail template) has no real closing tag for
      // its trailing whitespace to precede — that whitespace is just
      // incidental EOF fill, not meaningful formatting. Keeping it would
      // double-count against the Document-level trailing hardline above on
      // a second format pass (its "did the file already end in a newline"
      // input keeps changing each time). An element left open because it's
      // yielding to an *ancestor's* closing tag or Latte branch keyword
      // (e.g. a <td> implicitly closing before {else}) is different: that
      // trailing gap is real, meaningful spacing before whatever comes
      // next, so it's preserved.
      const trailBreak = node.unclosedAtEof ? "" : b.trailBreak;
      return [openTag, indent([b.leadBreak, ...b.inner]), trailBreak, closeTag];
    }

    case "HtmlComment":
    case "HtmlDoctype":
      return node.value;

    case "HtmlOrphanCloseTag":
      return ["</", node.name, ">"];

    case "LatteOutput":
      return [
        "{",
        node.form === "="
          ? "="
          : node.form === "_" || node.form === "translate"
            ? node.form + " "
            : "",
        exprToDoc(node.expr),
        "}",
      ];

    case "LatteComment":
      return ["{*", node.value, "*}"];

    case "LatteVar":
      return ["{var $", node.name, " = ", exprToDoc(node.value), "}"];

    case "LatteDo":
      return ["{do ", exprToDoc(node.expr), "}"];

    case "LatteBreakIf":
      return ["{breakIf ", exprToDoc(node.cond), "}"];

    case "LatteContentType":
      return ["{contentType ", node.value, "}"];

    case "LatteVarType":
      return ["{varType ", node.value, "}"];

    case "LatteTemplateType":
      return ["{templateType ", node.value, "}"];

    case "LatteLayout":
      return ["{", node.keyword, " ", exprToDoc(node.expr), "}"];

    case "LatteInclude": {
      const parts = ["{include ", exprToDoc(node.target)];
      for (const a of node.args) {
        parts.push(", ");
        parts.push(
          a.name ? [a.name, ": ", exprToDoc(a.value)] : exprToDoc(a.value),
        );
      }
      parts.push("}");
      return parts;
    }

    case "LatteCapture":
      return [
        "{capture $",
        node.varName,
        "}",
        ...node.body.map((n) => printInlinePart(n, options)),
        "{/capture}",
      ];

    case "LatteIf": {
      if (mode === "inline") {
        const parts = [["{if ", exprToDoc(node.cond), "}"]];
        node.consequent.forEach((n) => parts.push(printInlinePart(n, options)));
        for (const ei of node.elseifs) {
          parts.push(["{elseif ", exprToDoc(ei.cond), "}"]);
          ei.body.forEach((n) => parts.push(printInlinePart(n, options)));
        }
        if (node.alternate) {
          parts.push("{else}");
          node.alternate.forEach((n) =>
            parts.push(printInlinePart(n, options)),
          );
        }
        parts.push("{/if}");
        return parts;
      }
      const parts = [];
      const pushBranch = (head, bodyNodes) => {
        parts.push(head);
        const b = computeBlock(bodyNodes, options);
        if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      };
      pushBranch(["{if ", exprToDoc(node.cond), "}"], node.consequent);
      for (const ei of node.elseifs)
        pushBranch(["{elseif ", exprToDoc(ei.cond), "}"], ei.body);
      if (node.alternate) pushBranch("{else}", node.alternate);
      parts.push("{/if}");
      return parts;
    }

    case "LatteForeach": {
      const head = [
        "{foreach ",
        exprToDoc(node.iterable),
        " as ",
        varsDoc(node),
        "}",
      ];
      if (mode === "inline") {
        return [
          head,
          ...node.body.map((n) => printInlinePart(n, options)),
          "{/foreach}",
        ];
      }
      const parts = [head];
      const b = computeBlock(node.body, options);
      if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      parts.push("{/foreach}");
      return parts;
    }

    case "LatteSpaceless": {
      if (mode === "inline") {
        return [
          "{spaceless}",
          ...node.body.map((n) => printInlinePart(n, options)),
          "{/spaceless}",
        ];
      }
      const parts = ["{spaceless}"];
      const b = computeBlock(node.body, options);
      if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      parts.push("{/spaceless}");
      return parts;
    }

    case "LatteFor": {
      const head = [
        "{for ",
        exprToDoc(node.init),
        "; ",
        exprToDoc(node.cond),
        "; ",
        exprToDoc(node.step),
        "}",
      ];
      if (mode === "inline") {
        return [
          head,
          ...node.body.map((n) => printInlinePart(n, options)),
          "{/for}",
        ];
      }
      const parts = [head];
      const b = computeBlock(node.body, options);
      if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      parts.push("{/for}");
      return parts;
    }

    case "LatteBlock": {
      const head = node.name ? ["{block ", node.name, "}"] : ["{block}"];
      if (mode === "inline") {
        return [
          head,
          ...node.body.map((n) => printInlinePart(n, options)),
          "{/block}",
        ];
      }
      const parts = [head];
      const b = computeBlock(node.body, options);
      if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      parts.push("{/block}");
      return parts;
    }

    case "LatteDefine": {
      const head = ["{define ", node.name, "}"];
      if (mode === "inline") {
        return [
          head,
          ...node.body.map((n) => printInlinePart(n, options)),
          "{/define}",
        ];
      }
      const parts = [head];
      const b = computeBlock(node.body, options);
      if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      parts.push("{/define}");
      return parts;
    }

    default:
      throw new Error(`printer: unknown node type '${node.type}'`);
  }
}

// Named printLatte, not print: `print` is a built-in global in browser/Node
// environments and this repo's ESLint config flags shadowing it.
function printLatte(path, options) {
  const node = path.node !== undefined ? path.node : path.getValue();
  return printNode(node, options, "block");
}

module.exports = { print: printLatte, printNode, exprToDoc };
