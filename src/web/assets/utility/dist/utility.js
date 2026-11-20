/* AI Hreflang utility: shows the numbers of an hreflang bar's segment in a tooltip. It's one element that follows the
   segment, as a tooltip inside the table would be clipped by its scroll area. */
(function () {
  var tooltip = null;

  function hide() {
    if (tooltip) {
      tooltip.classList.remove('aih-tooltip-visible', 'aih-tooltip-gliding');
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

    // Moving between segments while it's showing glides to the next one, rather than fading in again
    var showing = tooltip.classList.contains('aih-tooltip-visible');

    tooltip.innerHTML = content.innerHTML;

    var rect = segment.getBoundingClientRect();
    var size = tooltip.getBoundingClientRect();
    var left = rect.left + rect.width / 2 - size.width / 2;
    var top = rect.top - size.height - 8;
    var placement = 'top';

    // Keep it on screen, and below the segment when there's no room above it
    left = Math.max(8, Math.min(left, window.innerWidth - size.width - 8));

    if (top < 8) {
      top = rect.bottom + 8;
      placement = 'bottom';
    }

    // The glide is a transition on left and top, so it's switched on before they change
    tooltip.classList.toggle('aih-tooltip-gliding', showing);
    tooltip.dataset.placement = placement;
    // The caret stays on the segment's middle, but inside the tooltip's rounded corners
    tooltip.style.setProperty('--aih-caret', Math.max(12, Math.min(rect.left + rect.width / 2 - left, size.width - 12)) + 'px');
    tooltip.style.left = left + 'px';
    tooltip.style.top = top + 'px';

    if (!showing) {
      // Let it be laid out in its hidden state first, so the transition runs
      void tooltip.offsetWidth;
      tooltip.classList.add('aih-tooltip-visible');
    }
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
