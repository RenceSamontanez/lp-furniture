/*
 * Stepper alignment audit (Stage 21h). Paste into the browser console, or the Browser pane's javascript tool, on a page that
 * shows a .lp-stepper (the customer's request status page), at 390px and at 1280px. Returns { pass, checked, failures }.
 *
 * For EVERY step of EVERY .lp-stepper on the page it measures with getBoundingClientRect:
 *   1. the circle's centre and the label's centre are within 1px horizontally,
 *   2. the number or icon is centred inside the circle (within 1px, both axes),
 *   3. the connector (the line to the previous step) starts and ends on circle centres: it spans from the previous
 *      circle's centre to this circle's centre, within 1px,
 *   4. the columns are equal width (within 1px),
 *   5. no label overflows its column and no two labels overlap.
 * To cover every status variant, run it on the status page of a request in each status (new, needs_info, approved,
 * refund_problem, refunded, declined, closed). docs/stage21h-screenshots has one per variant.
 */
(function () {
  const TOL = 1;
  const failures = [];
  let checked = 0;
  const near = (a, b) => Math.abs(a - b) <= TOL;
  const cx = (r) => r.left + r.width / 2;
  const cy = (r) => r.top + r.height / 2;

  document.querySelectorAll('.lp-stepper').forEach((ol, oi) => {
    const steps = [...ol.querySelectorAll('.lp-stepper__step')];
    const variant = [...ol.classList].find((c) => c.startsWith('lp-stepper--')) || 'lp-stepper--normal';
    const widths = steps.map((s) => s.getBoundingClientRect().width);
    if (Math.max(...widths) - Math.min(...widths) > TOL) {
      failures.push({ stepper: oi, variant, problem: 'columns are not equal width', widths });
    }
    let prevLabel = null;
    steps.forEach((li, i) => {
      checked++;
      const dot = li.querySelector('.lp-stepper__dot').getBoundingClientRect();
      const labelEl = [...li.querySelectorAll('.lp-stepper__long, .lp-stepper__short')].find((e) => e.getClientRects().length) || li.querySelector('.lp-stepper__label');
      const label = labelEl.getBoundingClientRect();
      const col = li.getBoundingClientRect();
      const where = { stepper: oi, variant, step: i + 1, viewport: innerWidth };

      if (!near(cx(dot), cx(label))) failures.push({ ...where, problem: 'circle and label centres differ', circle: cx(dot), label: cx(label) });
      if (!near(cx(dot), cx(col))) failures.push({ ...where, problem: 'circle is not centred in its column', circle: cx(dot), column: cx(col) });

      const inner = li.querySelector('.lp-stepper__dot svg, .lp-stepper__dot .lp-stepper__num');
      if (inner) {
        const r = inner.getBoundingClientRect();
        if (!near(cx(r), cx(dot)) || !near(cy(r), cy(dot))) failures.push({ ...where, problem: 'number or icon not centred in the circle', dx: cx(r) - cx(dot), dy: cy(r) - cy(dot) });
      }

      if (i > 0) {
        const prevDot = steps[i - 1].querySelector('.lp-stepper__dot').getBoundingClientRect();
        const line = getComputedStyle(li, '::before');
        const lineRight = col.left + col.width - parseFloat(line.right);
        const lineLeft = lineRight - parseFloat(line.width);
        if (!near(lineLeft, cx(prevDot)) || !near(lineRight, cx(dot))) {
          failures.push({ ...where, problem: 'connector does not run between circle centres', lineLeft, lineRight, from: cx(prevDot), to: cx(dot) });
        }
        const lineY = col.top + parseFloat(line.top) + parseFloat(line.height) / 2;
        if (!near(lineY, cy(dot))) failures.push({ ...where, problem: 'connector is not at circle centre height', lineY, circleY: cy(dot) });
      }

      if (label.left < col.left - TOL || label.right > col.right + TOL) failures.push({ ...where, problem: 'label overflows its column' });
      if (prevLabel && label.left < prevLabel.right - 0.5) failures.push({ ...where, problem: 'label overlaps the previous label' });
      prevLabel = label;
    });
  });

  if (!checked) failures.push({ problem: 'no .lp-stepper on this page' });
  return { pass: failures.length === 0, viewport: innerWidth, steppers: document.querySelectorAll('.lp-stepper').length, checked, failures };
})();
