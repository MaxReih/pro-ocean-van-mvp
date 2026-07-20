(function () {
  function project(point, bounds, width, height) {
    const lonSpan = Math.max(0.0001, bounds.maxLon - bounds.minLon);
    const latSpan = Math.max(0.0001, bounds.maxLat - bounds.minLat);
    return {
      x: ((point.lon - bounds.minLon) / lonSpan) * width,
      y: height - ((point.lat - bounds.minLat) / latSpan) * height
    };
  }

  function renderMap(element) {
    let points = [];
    try {
      points = JSON.parse(element.dataset.points || '[]');
    } catch (error) {
      points = [];
    }
    element.innerHTML = '';
    if (!points.length) {
      element.textContent = 'Keine Kartenpunkte vorhanden.';
      return;
    }

    const width = 640;
    const height = 260;
    const bounds = points.reduce((acc, point) => ({
      minLat: Math.min(acc.minLat, point.lat),
      maxLat: Math.max(acc.maxLat, point.lat),
      minLon: Math.min(acc.minLon, point.lon),
      maxLon: Math.max(acc.maxLon, point.lon)
    }), { minLat: 90, maxLat: -90, minLon: 180, maxLon: -180 });

    const pad = 0.08;
    const latPad = Math.max(0.02, (bounds.maxLat - bounds.minLat) * pad);
    const lonPad = Math.max(0.02, (bounds.maxLon - bounds.minLon) * pad);
    bounds.minLat -= latPad;
    bounds.maxLat += latPad;
    bounds.minLon -= lonPad;
    bounds.maxLon += lonPad;

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Karte mit Einsatzorten');

    const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    bg.setAttribute('width', String(width));
    bg.setAttribute('height', String(height));
    bg.setAttribute('rx', '8');
    bg.setAttribute('fill', '#F6F3EE');
    svg.appendChild(bg);

    points.forEach((point, index) => {
      const pos = project(point, bounds, width, height);
      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      circle.setAttribute('cx', String(pos.x));
      circle.setAttribute('cy', String(pos.y));
      circle.setAttribute('r', '8');
      circle.setAttribute('fill', index === 0 ? '#135476' : '#1F5C4B');
      svg.appendChild(circle);

      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      text.setAttribute('x', String(Math.min(width - 120, pos.x + 12)));
      text.setAttribute('y', String(Math.max(18, pos.y - 10)));
      text.setAttribute('fill', '#0B1B1F');
      text.setAttribute('font-size', '13');
      text.textContent = point.label || 'Ort';
      svg.appendChild(text);
    });

    element.appendChild(svg);
    if (element.dataset.attribution) {
      const attr = document.createElement('small');
      attr.className = 'pov-map-attribution';
      attr.textContent = element.dataset.attribution;
      element.appendChild(attr);
    }
  }

  window.POVLeaflet = {
    renderAll() {
      document.querySelectorAll('.pov-mini-map').forEach(renderMap);
    }
  };

  document.addEventListener('DOMContentLoaded', () => window.POVLeaflet.renderAll());
})();
