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
      return [exprToDoc(e.cond), " ? ", exprToDoc(e.then), " : ", exprToDoc(e.else)];
    case "Binary":
      return [exprToDoc(e.left), " ", e.op, " ", exprToDoc(e.right)];
    case "PropAccess":
      return [exprToDoc(e.object), "->", e.prop];
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
  return node.keyVar ? [exprToDoc(node.keyVar), " => ", exprToDoc(node.valueVar)] : exprToDoc(node.valueVar);
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

function gapDoc(gap) {
  if (!gap) return "";
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
function textFillDoc(core) {
  return core.split(/\s+/).filter(Boolean).join(" ");
}

// Returns null for an empty (whitespace-only-or-empty) body, else
// {leadBreak, inner, trailBreak} for the caller to wrap with indent().
function computeBlock(nodes, options) {
  const { items, trailingGap } = buildContentSequence(nodes);
  if (items.length === 0) return null;
  const inner = [];
  for (let i = 0; i < items.length; i++) {
    if (i > 0) inner.push(gapDoc(items[i].gapBefore));
    inner.push(items[i].kind === "text" ? textFillDoc(items[i].core) : printNode(items[i].node, options, "block"));
  }
  const lead = items[0].gapBefore;
  const leadBreak = lead.includes("\n") ? hardline : lead ? " " : "";
  const trailBreak = trailingGap.includes("\n") ? hardline : trailingGap ? " " : "";
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
  const valueParts = a.value.map((v) => (v.type === "HtmlText" ? v.value : printNode(v, options, "inline")));
  const q = a.quote || '"';
  return [a.name, "=", q, valueParts, q];
}

const SIMPLE_ATTR_PASSTHROUGH = new Set([
  "LatteOutput", "LatteComment", "LatteVar", "LatteDo", "LatteBreakIf", "LatteFor", "LatteDefine",
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
    const out = [["{foreach ", exprToDoc(item.iterable), " as ", varsDoc(item), "}"]];
    for (const it of item.body) out.push(...attrPieces(it, options));
    out.push("{/foreach}");
    return out;
  }
  throw new Error(`printer: unexpected attribute-list item type '${item.type}'`);
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
      const { items } = buildContentSequence(node.children);
      const parts = [];
      for (let i = 0; i < items.length; i++) {
        if (i > 0) parts.push(gapDoc(items[i].gapBefore));
        parts.push(items[i].kind === "text" ? textFillDoc(items[i].core) : printNode(items[i].node, options, "block"));
      }
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
      return [openTag, indent([b.leadBreak, ...b.inner]), b.trailBreak, closeTag];
    }

    case "HtmlComment":
    case "HtmlDoctype":
      return node.value;

    case "HtmlOrphanCloseTag":
      return ["</", node.name, ">"];

    case "LatteOutput":
      return ["{", node.form === "=" ? "=" : "", exprToDoc(node.expr), "}"];

    case "LatteComment":
      return ["{*", node.value, "*}"];

    case "LatteVar":
      return ["{var $", node.name, " = ", exprToDoc(node.value), "}"];

    case "LatteDo":
      return ["{do ", exprToDoc(node.expr), "}"];

    case "LatteBreakIf":
      return ["{breakIf ", exprToDoc(node.cond), "}"];

    case "LatteInclude": {
      const parts = ["{include ", exprToDoc(node.target)];
      for (const a of node.args) {
        parts.push(", ");
        parts.push(a.name ? [a.name, ": ", exprToDoc(a.value)] : exprToDoc(a.value));
      }
      parts.push("}");
      return parts;
    }

    case "LatteCapture":
      return ["{capture $", node.varName, "}", ...node.body.map((n) => printInlinePart(n, options)), "{/capture}"];

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
          node.alternate.forEach((n) => parts.push(printInlinePart(n, options)));
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
      for (const ei of node.elseifs) pushBranch(["{elseif ", exprToDoc(ei.cond), "}"], ei.body);
      if (node.alternate) pushBranch("{else}", node.alternate);
      parts.push("{/if}");
      return parts;
    }

    case "LatteForeach": {
      const head = ["{foreach ", exprToDoc(node.iterable), " as ", varsDoc(node), "}"];
      if (mode === "inline") {
        return [head, ...node.body.map((n) => printInlinePart(n, options)), "{/foreach}"];
      }
      const parts = [head];
      const b = computeBlock(node.body, options);
      if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      parts.push("{/foreach}");
      return parts;
    }

    case "LatteSpaceless": {
      if (mode === "inline") {
        return ["{spaceless}", ...node.body.map((n) => printInlinePart(n, options)), "{/spaceless}"];
      }
      const parts = ["{spaceless}"];
      const b = computeBlock(node.body, options);
      if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      parts.push("{/spaceless}");
      return parts;
    }

    case "LatteFor": {
      const head = ["{for ", exprToDoc(node.init), "; ", exprToDoc(node.cond), "; ", exprToDoc(node.step), "}"];
      if (mode === "inline") {
        return [head, ...node.body.map((n) => printInlinePart(n, options)), "{/for}"];
      }
      const parts = [head];
      const b = computeBlock(node.body, options);
      if (b) parts.push(indent([b.leadBreak, ...b.inner]), b.trailBreak);
      parts.push("{/for}");
      return parts;
    }

    case "LatteDefine": {
      const head = ["{define ", node.name, "}"];
      if (mode === "inline") {
        return [head, ...node.body.map((n) => printInlinePart(n, options)), "{/define}"];
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
