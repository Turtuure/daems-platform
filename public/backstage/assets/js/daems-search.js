/* Global search typeahead — shared by public top-nav and backstage top-bar. */
(function () {
    var containers = document.querySelectorAll('.daems-search');
    if (!containers.length) return;

    containers.forEach(function (root) {
        var input      = root.querySelector('.daems-search__input');
        var dropdown   = root.querySelector('.daems-search__dropdown');
        var resultsEl  = root.querySelector('.daems-search__results');
        var viewAllEl  = root.querySelector('.daems-search__view-all');
        var endpoint   = root.getAttribute('data-endpoint') || '/api/search';
        var baseUrl    = root.getAttribute('data-base-url') || '/search';
        var timer      = null;
        var lastQuery  = '';

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { fetchAndRender(input.value.trim()); }, 300);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { hide(); input.blur(); }
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) hide();
        });

        function fetchAndRender(q) {
            if (q.length < 2) { hide(); return; }
            if (q === lastQuery) return;
            lastQuery = q;
            fetch(endpoint + '?q=' + encodeURIComponent(q) + '&limit=5')
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    var items = (j && j.data) || [];
                    if (!items.length) { hide(); return; }
                    render(items, q);
                    show();
                })
                .catch(function () { hide(); });
        }

        function render(items, q) {
            resultsEl.innerHTML = '';
            var grouped = {};
            items.forEach(function (h) {
                grouped[h.entity_type] = grouped[h.entity_type] || [];
                grouped[h.entity_type].push(h);
            });
            Object.keys(grouped).forEach(function (type) {
                var header = document.createElement('div');
                header.className = 'daems-search__group-header';
                header.textContent = typeLabel(type);
                resultsEl.appendChild(header);
                grouped[type].forEach(function (h) { resultsEl.appendChild(row(h)); });
            });
            viewAllEl.setAttribute('href', baseUrl + '?q=' + encodeURIComponent(q));
        }

        function row(h) {
            var a = document.createElement('a');
            a.className = 'daems-search__result';
            a.setAttribute('href', h.url);
            var title = document.createElement('div');
            title.className = 'daems-search__result-title';
            title.textContent = h.title + (h.locale_code ? ' (' + h.locale_code.slice(0,2) + ')' : '');
            var snippet = document.createElement('div');
            snippet.className = 'daems-search__result-snippet';
            snippet.textContent = h.snippet;
            a.appendChild(title); a.appendChild(snippet);
            return a;
        }

        function typeLabel(t) {
            return { event: 'Events', project: 'Projects', insight: 'Insights', forum_topic: 'Forum', member: 'Members' }[t] || t;
        }

        function show() { dropdown.hidden = false; }
        function hide() { dropdown.hidden = true; }
    });
}());
