(function () {
  'use strict';

  var grid   = document.getElementById('parish-grid');
  var notice = document.getElementById('parish-sort-notice');

  if (!grid) return;

  // No geolocation support — leave default order
  if (!navigator.geolocation) {
    if (notice) notice.style.display = 'none';
    return;
  }

  if (notice) notice.textContent = 'Detecting your location to sort by distance…';

  navigator.geolocation.getCurrentPosition(
    function (pos) {
      var userLat = pos.coords.latitude;
      var userLng = pos.coords.longitude;

      // Haversine formula — returns distance in miles
      function distanceMi(lat1, lng1, lat2, lng2) {
        var R    = 3958.8;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a    = Math.sin(dLat / 2) * Math.sin(dLat / 2)
                 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
                 * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
      }

      var cards = Array.from(grid.children);

      cards.forEach(function (card) {
        var lat = parseFloat(card.dataset.lat);
        var lng = parseFloat(card.dataset.lng);

        if (!isNaN(lat) && !isNaN(lng)) {
          var dist = distanceMi(userLat, userLng, lat, lng);
          card.dataset.dist = dist;

          var badge = card.querySelector('.parish-distance');
          if (badge) {
            var label = dist < 1
              ? 'Less than 1 mi away'
              : Math.round(dist).toLocaleString() + ' mi away';
            badge.textContent = label;
            badge.style.display = 'inline-block';
          }
        } else {
          // No coordinates — push to end
          card.dataset.dist = Infinity;
        }
      });

      // Sort cards by distance ascending; Infinity values go last
      cards.sort(function (a, b) {
        return (parseFloat(a.dataset.dist) || Infinity)
             - (parseFloat(b.dataset.dist) || Infinity);
      });

      // Re-append in sorted order
      cards.forEach(function (card) { grid.appendChild(card); });

      if (notice) notice.textContent = 'Sorted by distance from your location.';
    },
    function () {
      // Denied or timed out — hide the notice, keep default order
      if (notice) notice.style.display = 'none';
    },
    { timeout: 8000, maximumAge: 60000 }
  );
})();
