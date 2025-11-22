# Virtual Scrolling Implementation Plan

## Overview
Implement virtual scrolling for rv_tscroller to handle millions of items efficiently by only rendering visible items in the DOM while maintaining scroll position and height calculations.

## Architecture

### Data Structure
```
RVTS Object additions:
├─ itemData[]        (all loaded items: {id, html, height})
├─ visibleStart      (first visible item index)
├─ visibleEnd        (last visible item index)
├─ itemHeights{}     (height map for each item)
├─ totalHeight       (sum of all item heights)
├─ domPool[]         (recycled <li> elements, ~80 max)
├─ itemHeightEstimate (default height for new items)
└─ scrollTracking    (last scroll position)

DOM Structure:
├─ topSpacer         (<div> accounting for off-screen items above)
├─ visibleContainer  (<ul> with max 80 recycled <li> elements)
└─ bottomSpacer      (<div> accounting for off-screen items below)
```

## Implementation Tasks

### Phase 1: Data Structure & Storage
- [x] **Task 1: Design virtual scroll data structure** ✅
  - Add itemData array to store {id, html, height}
  - Add height tracking object
  - Add DOM pool
  - Initialize RVTS properties
  - Call initVirtualScroll() in engage()

- [x] **Task 2: Implement item height tracking system** ✅
  - Measure actual item heights after rendering
  - Create height cache/map
  - Implement fallback estimation
  - Calculate totalHeight

- [x] **Task 3: Create DOM element pool for recycling** ✅
  - Pre-create ~80 <li> elements
  - Store in domPool array
  - Implement element reuse function
  - Clean up element content before reuse

### Phase 2: Viewport & Rendering
- [x] **Task 4: Implement viewport range calculation** ✅
  - Calculate which items should be visible based on scroll
  - Determine visibleStart and visibleEnd indices
  - Account for buffer zones for smooth scrolling

- [x] **Task 5: Create spacer elements** ✅
  - Create topSpacer div
  - Create bottomSpacer div
  - Dynamically update spacer heights (in updateVisibleRange)
  - Insert into DOM structure

- [x] **Task 6: Implement scroll event handler** ✅
  - Detect scroll position changes (onScroll)
  - Recalculate visible range (updateVisibleRange)
  - Trigger DOM updates (renderVisibleItems)
  - Handle resize events (throttleScroll)

### Phase 3: Integration & Logic
- [x] **Task 7: Refactor append logic for new items** ✅
  - Add items to itemData array instead of directly to DOM (parseItems, addItemsToData)
  - Trigger viewport recalculation (updateVisibleRange)
  - Measure new item heights (measureItemHeight)
  - Handle initial load (initVirtualScroll extracts existing items)

- [x] **Task 8: Implement item recycling** ✅
  - Update DOM element content when recycling (updatePooledElement)
  - Reposition elements in DOM order (renderVisibleItems)
  - Update classes/attributes (returnToPool clears content)

- [x] **Task 9: Update bottom-load trigger** ✅
  - Works with itemData array (calculateVisibleRange uses totalHeight)
  - Use totalHeight for calculations (getItemScrollOffset)
  - Keep 75% viewport buffer logic (checkAutoScroll uses window.innerHeight)

### Phase 4: Optimization & Testing
- [ ] **Task 10: Performance testing and optimization**
  - Test with 1,000 items
  - Test with 10,000 items
  - Test with 100,000+ items
  - Optimize height calculations
  - Smooth scroll performance
  - Memory usage analysis

- [ ] **Task 11: Cleanup and refactoring**
  - Remove old _removeFromTop() function
  - Remove scroll position compensation code
  - Clean up unused variables
  - Add code comments and documentation

## Status Summary

**Not Started:** 2/11 tasks
**In Progress:** 0/11 tasks
**Completed:** 9/11 tasks

### Completed Tasks
- ✅ Task 1: Design virtual scroll data structure (commit: cf7450e3c)
- ✅ Task 2: Implement item height tracking system (commit: 34287a0b7)
- ✅ Task 3: Create DOM element pool for recycling (commit: d18b4755a)
- ✅ Task 4: Implement viewport range calculation (commit: 547fcaec1)
- ✅ Task 5: Create spacer elements (done in Tasks 1 & 4)
- ✅ Task 6: Implement scroll event handler (commit: f7a762036)
- ✅ Task 7: Refactor append logic (commit: 3aedf09bd)
- ✅ Task 8: Implement item recycling (done in Task 4)
- ✅ Task 9: Update bottom-load trigger (already compatible)

## Notes
- Keep checkAutoScroll logic for determining when to load more
- Maintain smooth scrolling experience
- Ensure no janky rendering when recycling elements
- Test with actual large datasets
