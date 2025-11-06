'use strict';

(function () {
    const instances = document.querySelectorAll('.woo-live-search');

    if (!instances.length || typeof WooLiveSearchFilter === 'undefined') {
        return;
    }

    const debounce = (fn, wait = 250) => {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(null, args), wait);
        };
    };

    const encodeBody = (params) => {
        const body = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                body.append(key, value);
            }
        });
        return body.toString();
    };

    const renderSuggestions = (container, items) => {
        container.innerHTML = '';

        if (!items.length) {
            container.classList.remove('woo-live-search__suggestions--visible');
            return;
        }

        const list = document.createElement('ul');
        list.className = 'woo-live-search__suggestions-list';

        items.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'woo-live-search__suggestion';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'woo-live-search__suggestion-button';
            button.dataset.title = item.title;
            button.dataset.url = item.url;
            button.textContent = item.title;

            li.appendChild(button);
            list.appendChild(li);
        });

        container.appendChild(list);
        container.classList.add('woo-live-search__suggestions--visible');
    };

    const renderResults = (container, statusEl, payload) => {
        container.innerHTML = payload.html || '';

        if (payload.count > 0) {
            statusEl.textContent = `${payload.count}`;
            statusEl.dataset.state = 'loaded';
        } else {
            statusEl.textContent = WooLiveSearchFilter.texts.noResults;
            statusEl.dataset.state = 'empty';
        }
    };

    const sendRequest = async (action, data) => {
        const response = await fetch(WooLiveSearchFilter.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept': 'application/json',
            },
            body: encodeBody({
                action,
                ...data,
            }),
        });

        if (!response.ok) {
            throw new Error(response.statusText);
        }

        return response.json();
    };

    instances.forEach((root) => {
        const form = root.querySelector('.woo-live-search__form');
        const keywordInput = root.querySelector('.woo-live-search__input');
        const categorySelect = root.querySelector('.woo-live-search__select');
        const suggestionsBox = root.querySelector('.woo-live-search__suggestions');
        const resultsBox = root.querySelector('.woo-live-search__results');
        const statusBox = root.querySelector('.woo-live-search__status');

        const nonce = root.dataset.nonce;

        let suggestionsAbortController = null;
        let resultsAbortController = null;

        const abortSuggestions = () => {
            if (suggestionsAbortController) {
                suggestionsAbortController.abort();
                suggestionsAbortController = null;
            }
        };

        const abortResults = () => {
            if (resultsAbortController) {
                resultsAbortController.abort();
                resultsAbortController = null;
            }
        };

        const requestSuggestions = debounce(async () => {
            const keyword = keywordInput.value.trim();
            const category = categorySelect.value;

            if (!keyword) {
                abortSuggestions();
                renderSuggestions(suggestionsBox, []);
                return;
            }

            abortSuggestions();
            suggestionsAbortController = new AbortController();

            try {
                const response = await fetch(WooLiveSearchFilter.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                    },
                    body: encodeBody({
                        action: 'woo_live_search_suggestions',
                        keyword,
                        category,
                        nonce,
                    }),
                    signal: suggestionsAbortController.signal,
                });

                if (!response.ok) {
                    throw new Error(response.statusText);
                }

                const payload = await response.json();

                if (payload.success) {
                    renderSuggestions(suggestionsBox, payload.data || []);
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Suggestion request failed:', error);
                }
            }
        }, 200);

        const requestResults = async () => {
            const keyword = keywordInput.value.trim();
            const category = categorySelect.value;

            if (!keyword) {
                statusBox.textContent = WooLiveSearchFilter.texts.noResults;
                statusBox.dataset.state = 'empty';
                resultsBox.innerHTML = '';
                return;
            }

            statusBox.textContent = WooLiveSearchFilter.texts.loading;
            statusBox.dataset.state = 'loading';

            abortResults();
            resultsAbortController = new AbortController();

            try {
                const response = await fetch(WooLiveSearchFilter.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                    },
                    body: encodeBody({
                        action: 'woo_live_search_results',
                        keyword,
                        category,
                        nonce,
                    }),
                    signal: resultsAbortController.signal,
                });

                if (!response.ok) {
                    throw new Error(response.statusText);
                }

                const payload = await response.json();

                if (payload.success) {
                    renderResults(resultsBox, statusBox, payload.data);
                } else {
                    statusBox.textContent = payload.data?.message || WooLiveSearchFilter.texts.noResults;
                    statusBox.dataset.state = 'error';
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }

                console.error('Search request failed:', error);
                statusBox.textContent = error.message;
                statusBox.dataset.state = 'error';
            }
        };

        keywordInput.addEventListener('input', requestSuggestions);

        categorySelect.addEventListener('change', () => {
            requestSuggestions();
        });

        suggestionsBox.addEventListener('click', (event) => {
            const button = event.target.closest('.woo-live-search__suggestion-button');
            if (!button) {
                return;
            }

            keywordInput.value = button.dataset.title || '';
            renderSuggestions(suggestionsBox, []);
            requestResults();
        });

        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) {
                renderSuggestions(suggestionsBox, []);
            }
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            renderSuggestions(suggestionsBox, []);
            requestResults();
        });
    });
})();
