(function($) {
    'use strict';
    
    let searchTimeout;
    
    $(document).ready(function() {
        const $container = $('.typesense-search-container');
        const $mobileTrigger = $('.typesense-search-mobile-trigger');
        
        if (!$container.length && !$mobileTrigger.length) {
            return;
        }
        
        // Kontrola inicializace
        if (typeof typesenseSearch === 'undefined') {
            console.error('Typesense Search: typesenseSearch object is not defined');
            return;
        }
        
        const $input = $container.find('.typesense-search-input');
        // const $icon = $container.find('.typesense-search-icon'); // Icon removed
        const $button = $container.find('.typesense-search-button');
        const $loader = $container.find('.typesense-search-loader');
        const $results = $container.find('.typesense-search-results');
        const $closeButton = $container.find('.typesense-search-close');
        
        // Přidat overlay do body
        if ($('.typesense-search-overlay').length === 0) {
            $('body').append('<div class="typesense-search-overlay"></div>');
        }
        const $overlay = $('.typesense-search-overlay');
        
        // Mobile trigger handler - open search
        $(document).on('click', '.typesense-search-mobile-trigger', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Stop propagation to prevent immediate close by document handler
            
            // Find the associated container
            // Based on HTML structure, it is the next sibling
            let $targetContainer = $(this).next('.typesense-search-container');
            
            // Fallback: search globally if not found as sibling
            if (!$targetContainer.length) {
                 $targetContainer = $('.typesense-search-container');
            }
            
            $targetContainer.addClass('active');
            
            // Show overlay
            $('.typesense-search-overlay').addClass('active');
            // Ensure search bar is above overlay
            $targetContainer.css('z-index', '10001');
            
            const $targetInput = $targetContainer.find('.typesense-search-input');
            $targetInput.focus();
        });
        
        // Close button handler
        $(document).on('click', '.typesense-search-close', function(e) {
            e.preventDefault();
            const $targetContainer = $(this).closest('.typesense-search-container');
            const $targetInput = $targetContainer.find('.typesense-search-input');
            const $targetResults = $targetContainer.find('.typesense-search-results');
            
            $targetContainer.removeClass('active');
            $targetInput.val('');
            $targetResults.removeClass('active').empty();
            $('.typesense-search-overlay').removeClass('active');
            $targetInput.blur();
            
            // Reset z-index
            setTimeout(function() {
                $targetContainer.css('z-index', '');
            }, 200);
        });
        
        // History functions
        function getSearchHistory() {
            try {
                return JSON.parse(localStorage.getItem('typesense_search_history')) || [];
            } catch (e) {
                return [];
            }
        }
        
        function saveSearchHistory(query) {
            if (!query || query.length < 2) return;
            let history = getSearchHistory();
            // Remove existing if present
            history = history.filter(item => item !== query);
            // Add to start
            history.unshift(query);
            // Limit to 5
            history = history.slice(0, 5);
            localStorage.setItem('typesense_search_history', JSON.stringify(history));
        }
        
        function getViewedProducts() {
            try {
                return JSON.parse(localStorage.getItem('typesense_viewed_products')) || [];
            } catch (e) {
                return [];
            }
        }
        
        function saveViewedProduct(product) {
            if (!product || !product.id) return;
            
            // Save simple product data
            const productData = {
                id: product.id,
                name: product.name,
                image: product.image,
                permalink: product.permalink
            };
            
            let viewed = getViewedProducts();
            // Remove existing if present
            viewed = viewed.filter(item => item.id !== productData.id);
            // Add to start
            viewed.unshift(productData);
            // Limit to 6
            viewed = viewed.slice(0, 6);
            localStorage.setItem('typesense_viewed_products', JSON.stringify(viewed));
        }
        
        function loadRecommendedProducts() {
            showLoader();
            
            $.ajax({
                url: typesenseSearch.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'typesense_get_recommended',
                    nonce: typesenseSearch.nonce
                },
                success: function(response) {
                    hideLoader();
                    if (response.success && response.data.products && response.data.products.length > 0) {
                        renderRecommendedProducts(response.data.products);
                    }
                },
                error: function() {
                    hideLoader();
                }
            });
        }
        
        function renderRecommendedProducts(products) {
            $results.addClass('typesense-search-results-narrow');
            
            let html = '<div class="typesense-search-history">';
            html += '<div class="typesense-search-history-section">';
            html += '<div class="typesense-search-history-title">Doporučené produkty</div>';
            html += '<div class="typesense-search-history-products">';
            
            products.forEach(function(product) {
                const imageHtml = product.image ? 
                    '<img src="' + escapeHtml(product.image) + '" alt="' + escapeHtml(product.name) + '" class="typesense-search-history-product-image">' :
                    '<div class="typesense-search-history-product-image" style="background: #f5f5f5; display: flex; align-items: center; justify-content: center;"></div>';
                    
                html += '<a href="' + escapeHtml(product.permalink) + '" class="typesense-search-history-product">' +
                    imageHtml +
                    '<div class="typesense-search-history-product-name">' + escapeHtml(product.name) + '</div>' +
                    '</a>';
            });
            
            html += '</div></div></div>';
            
            $results.html(html);
            showResults();
        }
        
        function renderHistory() {
            const history = getSearchHistory();
            const viewed = getViewedProducts();
            
            if (history.length === 0 && viewed.length === 0) {
                // If no history, show recommended products
                loadRecommendedProducts();
                return;
            }
            
            $results.addClass('typesense-search-results-narrow');
            
            let html = '<div class="typesense-search-history">';
            
            if (history.length > 0) {
                html += '<div class="typesense-search-history-section">';
                html += '<div class="typesense-search-history-title">Naposledy vyhledávané</div>';
                html += '<div class="typesense-search-history-tags">';
                history.forEach(function(term) {
                    html += '<div class="typesense-search-history-tag" data-query="' + escapeHtml(term) + '">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">' +
                        '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />' +
                        '</svg>' +
                        escapeHtml(term) + '</div>';
                });
                html += '</div></div>';
            }
            
            if (viewed.length > 0) {
                html += '<div class="typesense-search-history-section">';
                html += '<div class="typesense-search-history-title">Naposledy navštívené produkty</div>';
                html += '<div class="typesense-search-history-products">';
                viewed.forEach(function(product) {
                    const imageHtml = product.image ? 
                        '<img src="' + escapeHtml(product.image) + '" alt="' + escapeHtml(product.name) + '" class="typesense-search-history-product-image">' :
                        '<div class="typesense-search-history-product-image" style="background: #f5f5f5; display: flex; align-items: center; justify-content: center;"></div>';
                        
                    html += '<a href="' + escapeHtml(product.permalink) + '" class="typesense-search-history-product">' +
                        imageHtml +
                        '<div class="typesense-search-history-product-name">' + escapeHtml(product.name) + '</div>' +
                        '</a>';
                });
                html += '</div></div>';
            }
            
            html += '</div>';
            
            $results.html(html);
            showResults();
        }
        
        // Handle input
        $input.on('input', function() {
            const query = $(this).val().trim();
            
            clearTimeout(searchTimeout);
            
            // Pokud je query prázdná, zobrazit historii/doporučené
            if (query.length === 0) {
                renderHistory();
                return;
            }
            
            // Pokud je query kratší než 2 znaky, nechat zobrazené doporučené/historii
            // ale nespouštět vyhledávání
            if (query.length < 2) {
                // Nechat zobrazené stávající výsledky (doporučené/historie)
                return;
            }
            
            showLoader();
            
            // 150ms delay - velmi rychlá odezva, stále optimalizované pro rychlé psaní
            searchTimeout = setTimeout(function() {
                performSearch(query);
            }, 150);
        });
        
        // Handle focus and click
        $input.on('focus click', function(e) {
            // Stop propagation aby document click handler hned nezavřel results
            e.stopPropagation();
            
            const query = $(this).val().trim();
            if (query.length === 0) {
                renderHistory();
            } else if ($results.html().trim() && $results.find('.typesense-search-item').length > 0) {
                showResults();
            }
        });
        
        // Handle history tag click
        $(document).on('click', '.typesense-search-history-tag', function() {
            const query = $(this).data('query');
            // Redirect to standard WordPress search page
            // Construct URL: home_url/?s=query
            const separator = typesenseSearch.homeUrl.indexOf('?') === -1 ? '?' : '&';
            window.location.href = typesenseSearch.homeUrl + separator + 's=' + encodeURIComponent(query);
        });
        
        // Handle product click to save history
        $(document).on('click', '.typesense-search-product-card', function() {
            const $card = $(this);
            const query = $input.val().trim();
            
            // Log successful click if there was a query
            // This confirms "good result" for the query
            if (query && query.length >= 2) {
                logSearch(query, true);
            }

            const product = {
                id: $card.attr('href'), // Use URL as ID for uniqueness
                name: $card.find('.typesense-search-product-name').text().trim(),
                permalink: $card.attr('href'),
                image: $card.find('img').attr('src')
            };
            
            saveViewedProduct(product);
        });

        
        // Hide results on click outside
        $(document).on('click', function(e) {
            // Check if click is outside container AND not on the trigger button
            if (!$(e.target).closest('.typesense-search-container').length && 
                !$(e.target).closest('.typesense-search-mobile-trigger').length) {
                hideResults();
            }
        });
        
        
        // --- ANALYTICS FUNCTIONS ---
        
        /**
         * Log search query to lightweight analytics
         * 
         * @param {string} query Search term
         * @param {boolean} hasResults Whether the search returned any results
         */
        function logSearch(query, hasResults) {
            if (!query || query.length < 2) return;
            
            // Use sendBeacon for best performance (doesn't block main thread, works on unload)
            // Fallback to fetch with keepalive
            
            const endpoint = typesenseSearch.homeUrl + '/wp-json/typesense/v1/log';
            const data = {
                query: query,
                has_results: hasResults,
                nonce: typesenseSearch.nonce
            };
            
            // Send as JSON
            if (navigator.sendBeacon) {
                // sendBeacon requires Blob for JSON content type usually, or FormData.
                // Since our API expects JSON or simple POST, let's use FormData for widest compatibility with sendBeacon
                // Or simply append params to URL if WP REST API supports it easily?
                // WP REST API handles JSON body best.
                
                const blob = new Blob([JSON.stringify(data)], {
                    type: 'application/json'
                });
                
                // Add header via Blob not possible directly in sendBeacon standard interface easily 
                // without relying on server reading text/plain.
                // So we will stick to FETCH with keepalive: true which is modern and standard replacement.
                
                fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': typesenseSearch.nonce
                    },
                    body: JSON.stringify(data),
                    keepalive: true
                }).catch(err => {
                    // Fail silently, it's just analytics
                    // console.warn('Typesense analytics failed', err);
                });
            } else {
                // Fallback for very old browsers
                $.ajax({
                    url: endpoint,
                    method: 'POST',
                    contentType: 'application/json',
                    headers: {
                        'X-WP-Nonce': typesenseSearch.nonce
                    },
                    data: JSON.stringify(data)
                });
            }
        }

        // Handle keys (Escape and Enter)
        $input.on('keydown', function(e) {
            if (e.key === 'Escape') {
                hideResults();
                $input.blur();
            } else if (e.key === 'Enter') {
                const query = $(this).val().trim();
                if (query.length > 0) {
                    // Log explicit search submission (Enter key)
                    // We assume it has results if the user is submitting, or we can check $results
                    // But usually Enter -> means user wants to see results page.
                    // We'll log it as "has results" true for now, as the results page will show something or "no results"
                    logSearch(query, true);

                    // Uložit do historie vyhledávání
                    if (query.length >= 2) {
                        saveSearchHistory(query);
                    }
                    
                    // Redirect to standard WordPress search page
                    // Construct URL: home_url/?s=query
                    const separator = typesenseSearch.homeUrl.indexOf('?') === -1 ? '?' : '&';
                    window.location.href = typesenseSearch.homeUrl + separator + 's=' + encodeURIComponent(query);
                }
            }
        });
        
        // Handle button click
        $button.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent closing overlay immediately if it relies on doc click
            
            const query = $input.val().trim();
            if (query.length > 0) {
                // Log explicit search submission (Button click)
                logSearch(query, true);

                // Uložit do historie vyhledávání
                if (query.length >= 2) {
                    saveSearchHistory(query);
                }
                
                // Redirect
                const separator = typesenseSearch.homeUrl.indexOf('?') === -1 ? '?' : '&';
                window.location.href = typesenseSearch.homeUrl + separator + 's=' + encodeURIComponent(query);
            } else {
                $input.focus();
            }
        });
        
        function performSearch(query) {
            $.ajax({
                url: typesenseSearch.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'typesense_search',
                    query: query,
                    type: 'all',
                    nonce: typesenseSearch.nonce
                },
                success: function(response) {
                    if (response && response.success) {
                        const responseData = response.data || {};
                        displayResults(responseData);
                        
                        // Check if we have any results (products, categories, or brands)
                        const hasProducts = responseData.products && responseData.products.length > 0;
                        const hasCategories = responseData.categories && responseData.categories.length > 0;
                        const hasBrands = responseData.brands && responseData.brands.length > 0;
                        
                        // If NO results at all, log it immediately as "zero results"
                        if (!hasProducts && !hasCategories && !hasBrands) {
                            logSearch(query, false);
                        }
                    } else {
                        console.error('Typesense Search Error:', response);
                        let errorMsg = 'Chyba při vyhledávání';
                        if (response && response.data && response.data.message) {
                            errorMsg = response.data.message;
                        }
                        $results.html('<div class="typesense-search-no-results">' + errorMsg + '</div>');
                        showResults();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Typesense Search AJAX Error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    
                    let errorMsg = 'Chyba připojení k serveru';
                    if (xhr.status === 403) {
                        errorMsg = 'Chyba oprávnění - zkuste obnovit stránku';
                    } else if (xhr.status === 500) {
                        errorMsg = 'Chyba serveru - kontaktujte administrátora';
                    } else if (xhr.responseText) {
                        try {
                            const errorData = JSON.parse(xhr.responseText);
                            if (errorData.data && errorData.data.message) {
                                errorMsg = errorData.data.message;
                            }
                        } catch (e) {
                            // Ignorovat parsing error
                        }
                    }
                    
                    $results.html('<div class="typesense-search-no-results">' + errorMsg + '</div>');
                    showResults();
                },
                complete: function() {
                    hideLoader();
                }
            });
        }
        
        function displayResults(data) {
            if (!data) {
                console.error('Typesense Search: No data received');
                $results.html('<div class="typesense-search-no-results">Žádná data</div>');
                showResults();
                return;
            }
            
            let mainHtml = '';
            let sidebarHtml = '';
            
            // Main - Produkty v gridu (první)
            if (data.products && Array.isArray(data.products) && data.products.length > 0) {
                mainHtml += '<div class="typesense-search-section">';
                mainHtml += '<div class="typesense-search-section-title">Produkty</div>';
                mainHtml += '<div class="typesense-search-products-grid">';
                data.products.forEach(function(product) {
                    mainHtml += renderProduct(product);
                });
                mainHtml += '</div>';
                mainHtml += '</div>';
            }
            
            // Sidebar - Kategorie a Značky (druhé)
            if ((data.categories && Array.isArray(data.categories) && data.categories.length > 0) ||
                (data.brands && Array.isArray(data.brands) && data.brands.length > 0)) {
                
                // Categories
                if (data.categories && Array.isArray(data.categories) && data.categories.length > 0) {
                    sidebarHtml += '<div class="typesense-search-section">';
                    sidebarHtml += '<div class="typesense-search-section-title">Kategorie</div>';
                    data.categories.forEach(function(category) {
                        sidebarHtml += renderCategory(category);
                    });
                    sidebarHtml += '</div>';
                }
                
                // Brands
                if (data.brands && Array.isArray(data.brands) && data.brands.length > 0) {
                    sidebarHtml += '<div class="typesense-search-section">';
                    sidebarHtml += '<div class="typesense-search-section-title">Značky</div>';
                    data.brands.forEach(function(brand) {
                        sidebarHtml += renderBrand(brand);
                    });
                    sidebarHtml += '</div>';
                }
            }
            
            // Sestavit finální HTML - Sidebar (kategorie) vlevo, Main (produkty) vpravo na desktopu
            let html = '';
            let hasResults = false;
            
            if (mainHtml || sidebarHtml) {
                hasResults = true;
                html = '<div class="typesense-search-results-content">';
                if (sidebarHtml) {
                    html += '<div class="typesense-search-sidebar">' + sidebarHtml + '</div>';
                }
                if (mainHtml) {
                    html += '<div class="typesense-search-main">' + mainHtml + '</div>';
                }
                html += '</div>';
            } else {
                html = '<div class="typesense-search-no-results">Žádné výsledky</div>';
            }
            
            if (hasResults) {
                $results.removeClass('typesense-search-results-narrow');
            } else {
                $results.addClass('typesense-search-results-narrow');
            }
            
            $results.html(html);
            showResults();
        }
        
        function renderProduct(product) {
            let priceHtml = '';
            
            // Zkontrolovat, jestli je produkt ve slevě
            const hasSale = product.sale_price !== null && 
                           product.sale_price !== undefined && 
                           product.regular_price !== null && 
                           product.regular_price !== undefined &&
                           product.sale_price < product.regular_price;
            
            if (hasSale) {
                // Produkt je ve slevě - zobrazit původní cenu přeškrtnutou a slevovou cenu
                priceHtml = '<div class="typesense-search-product-price">' +
                    '<span class="regular">' + formatPrice(product.regular_price) + '</span>' +
                    '<span class="sale">' + formatPrice(product.sale_price) + '</span>' +
                    '</div>';
            } else if (product.price !== null && product.price !== undefined) {
                // Normální cena
                priceHtml = '<div class="typesense-search-product-price">' + formatPrice(product.price) + '</div>';
            } else if (product.regular_price !== null && product.regular_price !== undefined) {
                // Fallback na regular_price
                priceHtml = '<div class="typesense-search-product-price">' + formatPrice(product.regular_price) + '</div>';
            }
            
            // Sklad status
            const stockStatus = product.stock_status || 'instock';
            let stockClass = 'out-of-stock';
            let stockText = 'Není skladem';
            
            if (stockStatus === 'instock') {
                stockClass = 'in-stock';
                stockText = 'Skladem';
            } else if (stockStatus === 'onbackorder') {
                stockClass = 'on-backorder';
                stockText = 'Na objednávku';
            }
            
            const stockHtml = '<div class="typesense-search-product-stock ' + stockClass + '">' + stockText + '</div>';
            
            // Výrobce - zobrazit pokud existuje, jinak použít první značku z brands pole
            let manufacturer = product.manufacturer || '';
            if (!manufacturer && product.brands && Array.isArray(product.brands) && product.brands.length > 0) {
                manufacturer = product.brands[0];
            }
            const manufacturerHtml = manufacturer ? 
                '<div class="typesense-search-product-manufacturer">' + escapeHtml(manufacturer) + '</div>' : '';
            
            const imageHtml = product.image ? 
                '<div class="typesense-search-product-image-wrapper">' +
                '<img src="' + escapeHtml(product.image) + '" alt="' + escapeHtml(product.name) + '" class="typesense-search-product-image">' +
                '</div>' :
                '<div class="typesense-search-product-image-wrapper"><div class="typesense-search-product-image"></div></div>';
            
            return '<a href="' + escapeHtml(product.permalink) + '" class="typesense-search-product-card">' +
                imageHtml +
                '<div class="typesense-search-product-content">' +
                '<div class="typesense-search-product-name">' + escapeHtml(product.name) + '</div>' +
                '<div class="typesense-search-product-info">' +
                '<div class="typesense-search-product-meta">' +
                manufacturerHtml +
                stockHtml +
                '</div>' +
                priceHtml +
                '</div>' +
                '</div>' +
                '</a>';
        }
        
        function renderCategory(category) {
            const categoryIcon = '<div class="typesense-search-item-icon">' +
                '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                '<path d="M13.333 13.3333C13.6866 13.3333 14.0258 13.1929 14.2758 12.9428C14.5259 12.6928 14.6663 12.3536 14.6663 12V5.33333C14.6663 4.97971 14.5259 4.64057 14.2758 4.39052C14.0258 4.14048 13.6866 4 13.333 4H8.06634C7.84335 4.00219 7.62337 3.94841 7.42654 3.84359C7.22971 3.73877 7.06231 3.58625 6.93967 3.4L6.39967 2.6C6.27827 2.41565 6.11299 2.26432 5.91867 2.1596C5.72436 2.05488 5.50708 2.00004 5.28634 2H2.66634C2.31272 2 1.97358 2.14048 1.72353 2.39052C1.47348 2.64057 1.33301 2.97971 1.33301 3.33333V12C1.33301 12.3536 1.47348 12.6928 1.72353 12.9428C1.97358 13.1929 2.31272 13.3333 2.66634 13.3333H13.333Z" fill="#FFB612" stroke="#FFB612" stroke-width="0.5" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>' +
                '</div>';
            
            return '<a href="' + escapeHtml(category.permalink) + '" class="typesense-search-item">' +
                categoryIcon +
                '<div class="typesense-search-item-content">' +
                '<div class="typesense-search-item-name">' + escapeHtml(category.name) + '</div>' +
                '</div>' +
                '</a>';
        }
        
        function renderBrand(brand) {
            const imageHtml = brand.image ? 
                '<img src="' + escapeHtml(brand.image) + '" alt="' + escapeHtml(brand.name) + '" class="typesense-search-item-image">' :
                '<div class="typesense-search-item-image"></div>';
            
            return '<a href="' + escapeHtml(brand.permalink) + '" class="typesense-search-item">' +
                imageHtml +
                '<div class="typesense-search-item-content">' +
                '<div class="typesense-search-item-name">' + escapeHtml(brand.name) + '</div>' +
                '</div>' +
                '</a>';
        }
        
        function formatPrice(price) {
            if (!price) return '';
            return new Intl.NumberFormat('cs-CZ', {
                style: 'currency',
                currency: 'CZK',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(price);
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text ? text.replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
        }
        
        function showLoader() {
            $loader.show();
        }
        
        function hideLoader() {
            $loader.hide();
        }
        
        function showOverlay() {
            $overlay.addClass('active');
            // Ensure search bar is above overlay. 
            // If parent creates stacking context, this might not be enough, but it's a start.
            $('.typesense-search-container').css('z-index', '1001'); 
        }
        
        function hideOverlay() {
            $overlay.removeClass('active');
            setTimeout(function() {
                $('.typesense-search-container').css('z-index', '');
            }, 200);
        }
        
        function showResults() {
            $container.addClass('active');
            $results.addClass('active').removeClass('empty');
            showOverlay();
        }
        
        function hideResults() {
            $('.typesense-search-container').removeClass('active');
            $('.typesense-search-results').removeClass('active').addClass('empty');
            hideOverlay();
        }
        
        // Zavřít při kliknutí na overlay
        $overlay.on('click', function() {
            hideResults();
        });
    });
    
})(jQuery);

