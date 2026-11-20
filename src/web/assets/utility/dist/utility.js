/* AI Hreflang utility: shows the numbers of an hreflang bar's segment in a tooltip. It's one element that follows the
   segment, as a tooltip inside the table would be clipped by its scroll area. */
(function () {
  var tooltip = null;

  function hide() {
    if (tooltip) {
      tooltip.hidden = true;
    }
  }

  function show(segment) {
    var content = segment.querySelector('.aih-tip');

    if (!content) {
      return;
    }

    if (!tooltip) {
      tooltip = document.createElement('div');
      tooltip.className = 'aih-tooltip';
      document.body.appendChild(tooltip);
    }

    tooltip.innerHTML = content.innerHTML;
    tooltip.hidden = false;

    var rect = segment.getBoundingClientRect();
    var size = tooltip.getBoundingClientRect();
    var left = rect.left + rect.width / 2 - size.width / 2;
    var top = rect.top - size.height - 8;

    // Keep it on screen, and below the segment when there's no room above it
    left = Math.max(8, Math.min(left, window.innerWidth - size.width - 8));

    if (top < 8) {
      top = rect.bottom + 8;
    }

    tooltip.style.left = left + 'px';
    tooltip.style.top = top + 'px';
  }

  function segmentOf(event) {
    return event.target.closest ? event.target.closest('.aih-seg') : null;
  }

  document.addEventListener('mouseover', function (event) {
    var segment = segmentOf(event);

    if (segment) {
      show(segment);
    } else {
      hide();
    }
  });

  document.addEventListener('focusin', function (event) {
    var segment = segmentOf(event);

    if (segment) {
      show(segment);
    }
  });

  document.addEventListener('focusout', hide);
  document.addEventListener('scroll', hide, true);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      hide();
    }
  });
})();
