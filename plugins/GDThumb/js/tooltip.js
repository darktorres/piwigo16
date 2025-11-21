/**
 * Simple vanilla JS tooltip library
 * Shows tooltips on elements with title attributes
 */
(function () {
    var activeTooltip = null;
    var tooltipDelay = null;

    // Create and return a tooltip element
    function createTooltip(text) {
        var tooltip = document.createElement('div');
        tooltip.className = 'gdthumb-tooltip';
        tooltip.textContent = text;
        document.body.appendChild(tooltip);
        return tooltip;
    }

    // Position tooltip near the target element
    function positionTooltip(tooltip, element) {
        var rect = element.getBoundingClientRect();
        var tooltipRect = tooltip.getBoundingClientRect();

        // Position above the element
        var top = rect.top - tooltipRect.height - 10;
        var left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);

        // Keep tooltip within viewport
        if (left < 5) left = 5;
        if (left + tooltipRect.width > window.innerWidth - 5) {
            left = window.innerWidth - tooltipRect.width - 5;
        }

        // If tooltip would go above viewport, show below instead
        if (top < 5) {
            top = rect.bottom + 10;
        }

        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
    }

    // Show tooltip
    function showTooltip(element) {
        if (activeTooltip) {
            activeTooltip.remove();
        }

        var title = element.getAttribute('title');
        if (!title) return;

        var tooltip = createTooltip(title);
        // Need small delay for getBoundingClientRect to work correctly
        setTimeout(function () {
            positionTooltip(tooltip, element);
            tooltip.classList.add('visible');
        }, 0);

        activeTooltip = tooltip;
    }

    // Hide tooltip
    function hideTooltip() {
        if (activeTooltip) {
            activeTooltip.classList.remove('visible');
            setTimeout(function () {
                if (activeTooltip) {
                    activeTooltip.remove();
                    activeTooltip = null;
                }
            }, 200); // Match CSS transition
        }
    }

    // Clear any pending tooltip show
    function clearTooltipDelay() {
        if (tooltipDelay) {
            clearTimeout(tooltipDelay);
            tooltipDelay = null;
        }
    }

    // Initialize tooltips on all elements with title attribute
    function init() {
        document.addEventListener('mouseenter', function (e) {
            var element = e.target;
            if (element.getAttribute('title')) {
                clearTooltipDelay();
                tooltipDelay = setTimeout(function () {
                    showTooltip(element);
                }, 500); // 500ms delay before showing
            }
        }, true);

        document.addEventListener('mouseleave', function (e) {
            var element = e.target;
            if (element.getAttribute('title')) {
                clearTooltipDelay();
                hideTooltip();
            }
        }, true);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
