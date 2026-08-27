// switchbox.ts's own side effect only (docs/PLAN.md P48) -- drains
// `window.SwitchBox` once loaded; this file is one of its 2 real
// pushers (the other, PictureView's own picture.ts, has its own
// separate direct import).
import "./switchbox";

export {};

window.SwitchBox = window.SwitchBox || [];
window.SwitchBox.push("#cmdRelatedTags", "#relatedTagsBox");
window.SwitchBox.push("#sortOrderLink", "#sortOrderBox");
window.SwitchBox.push("#derivativeSwitchLink", "#derivativeSwitchBox");
window.SwitchBox.push("#calendarViewSwitchLink", "#calendarViewSwitchBox");
