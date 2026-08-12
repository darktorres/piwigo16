"use strict";

const { parse } = require("./parser.cjs");
// Renamed on import too: `print` is a built-in global in browser/Node
// environments and this repo's ESLint config flags shadowing it.
const { print: printLatte } = require("./printer.cjs");

function locStart(node) {
  return node.start;
}
function locEnd(node) {
  return node.end;
}

module.exports = {
  languages: [
    {
      name: "latte",
      parsers: ["latte-ast"],
      extensions: [".latte"],
    },
  ],
  parsers: {
    "latte-ast": {
      parse: (text) => parse(text),
      astFormat: "latte-ast",
      locStart,
      locEnd,
    },
  },
  printers: {
    "latte-ast": {
      print: (path, options) => printLatte(path, options),
    },
  },
};
