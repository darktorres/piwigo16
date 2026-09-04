interface TemporaryStateAttrChange {
  el: Element;
  attribute: string;
  value: string | null;
}

interface TemporaryStateClassChange {
  el: Element;
  state: boolean;
  class: string;
}

interface TemporaryStateHtmlChange {
  el: Element;
  html: string;
}

function toElements(target: Element | ArrayLike<Element>): Element[] {
  return target instanceof Element ? [target] : Array.from(target);
}

// Class to implement a temporary state and reverse it
//
// Converted off jQuery with tags.ts (P49-A) -- its other real caller,
// group_list.ts, is still unconverted, so its own 8 call sites pass
// `document.querySelectorAll(...)` in place of their prior `$(...)`
// without the rest of that file changing.
export class TemporaryState {
  attrChanges: TemporaryStateAttrChange[];
  classChanges: TemporaryStateClassChange[];
  htmlChanges: TemporaryStateHtmlChange[];

  constructor() {
    //Arrays to reverse changes
    this.attrChanges = []; //Attribute changes : {el, attribute, value}
    this.classChanges = []; //Class changes : {el, state(add:true/remove:false), class}
    this.htmlChanges = []; //Html changes : {el, html}
  }

  /** Change temporarily an attribute of every element in the set. */
  changeAttribute(
    target: Element | ArrayLike<Element>,
    attrName: string,
    tempVal: string,
  ): void {
    for (const el of toElements(target)) {
      this.attrChanges.push({
        el,
        attribute: attrName,
        value: el.getAttribute(attrName),
      });
      el.setAttribute(attrName, tempVal);
    }
  }

  /** Add/remove a class temporarily on every element in the set. */
  changeClass(
    target: Element | ArrayLike<Element>,
    st: boolean,
    tempclass: string,
  ): void {
    for (const el of toElements(target)) {
      if (!(el.classList.contains(tempclass) && st)) {
        this.classChanges.push({ el, state: !st, class: tempclass });
        if (st) el.classList.add(tempclass);
        else el.classList.remove(tempclass);
      }
    }
  }

  /** Add a class temporarily to every element in the set. */
  addClass(target: Element | ArrayLike<Element>, tempclass: string): void {
    this.changeClass(target, true, tempclass);
  }

  /** Remove a class temporarily from every element in the set. */
  removeClass(target: Element | ArrayLike<Element>, tempclass: string): void {
    this.changeClass(target, false, tempclass);
  }

  /**
   * Change temporarily the HTML of every element in the set (removes
   * event handlers on the actual content).
   */
  changeHTML(target: Element | ArrayLike<Element>, temphtml: string): void {
    for (const el of toElements(target)) {
      this.htmlChanges.push({ el, html: el.innerHTML });
      el.innerHTML = temphtml;
    }
  }

  /** Reverse all the changes and clear the history. */
  reverse(): void {
    this.attrChanges.forEach(function (change) {
      if (change.value === null) {
        change.el.removeAttribute(change.attribute);
      } else {
        change.el.setAttribute(change.attribute, change.value);
      }
    });
    this.classChanges.forEach(function (change) {
      if (change.state) change.el.classList.add(change.class);
      else change.el.classList.remove(change.class);
    });
    this.htmlChanges.forEach(function (change) {
      change.el.innerHTML = change.html;
    });
    this.attrChanges = [];
    this.classChanges = [];
    this.htmlChanges = [];
  }
}
