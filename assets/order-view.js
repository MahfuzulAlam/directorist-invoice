( function () {
    'use strict';

    var config = window.directoristInvoiceOrderView;
    if ( ! config ) {
        return;
    }

    var cache = {};
    var scheduled = false;
    var activeActionOrderId = null;

    function getLinks( orderId ) {
        if ( ! Object.prototype.hasOwnProperty.call( cache, orderId ) ) {
            cache[ orderId ] = fetch( config.endpoint + encodeURIComponent( orderId ) + '/links', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': config.restNonce }
            } )
                .then( function ( response ) {
                    return response.ok ? response.json() : null;
                } )
                .catch( function () {
                    return null;
                } );
        }
        return cache[ orderId ];
    }

    function createIcon( type ) {
        var icon = document.createElement( 'i' );
        icon.className = 'fas ' + ( 'view' === type ? 'fa-file-alt' : 'fa-download' );
        icon.setAttribute( 'aria-hidden', 'true' );
        return icon;
    }

    function createActions( orderId, links ) {
        if ( ! links || ! links.view_url || ! links.download_url ) {
            return null;
        }

        var actions = document.createElement( 'span' );
        actions.className = 'directorist-invoice-order-actions is-icon-only';
        actions.dataset.orderId = orderId;
        actions.addEventListener( 'click', function ( event ) {
            event.stopPropagation();
        } );

        var view = document.createElement( 'a' );
        view.href = links.view_url;
        view.target = '_blank';
        view.rel = 'noopener';
        view.title = config.viewLabel;
        view.setAttribute( 'aria-label', config.viewLabel );
        view.appendChild( createIcon( 'view' ) );

        var download = document.createElement( 'a' );
        download.href = links.download_url;
        download.title = config.downloadLabel;
        download.setAttribute( 'aria-label', config.downloadLabel );
        download.appendChild( createIcon( 'download' ) );

        actions.appendChild( view );
        actions.appendChild( download );
        return actions;
    }

    function updateAdmin() {
        var match = window.location.hash.match( /^#\/edit\/(\d+)/ );
        var host = document.querySelector( '.directorist-orders-page .directorist-order-details-label' );
        if ( ! match || ! host ) {
            return;
        }

        var orderId = match[ 1 ];
        var existing = host.querySelector( '.directorist-invoice-order-actions' );
        if ( existing && existing.dataset.orderId === orderId ) {
            return;
        }
        if ( host.dataset.invoiceRequest === orderId ) {
            return;
        }
        host.dataset.invoiceRequest = orderId;

        getLinks( orderId ).then( function ( links ) {
            delete host.dataset.invoiceRequest;
            if ( ! links || window.location.hash.indexOf( '#/edit/' + orderId ) !== 0 || ! host.isConnected ) {
                return;
            }
            var old = host.querySelector( '.directorist-invoice-order-actions' );
            if ( old ) {
                old.remove();
            }
            var actions = createActions( orderId, links );
            if ( actions ) {
                actions.style.marginLeft = '16px';
                host.appendChild( actions );
            }
        } );
    }

    function orderIdFromRow( row ) {
        var orderLink = row.querySelector( 'a[href*="#/edit/"]' );
        var linkMatch = orderLink && orderLink.href.match( /#\/edit\/(\d+)/ );
        if ( linkMatch ) {
            return linkMatch[ 1 ];
        }
        var cells = row.querySelectorAll( 'td' );
        for ( var index = 0; index < Math.min( cells.length, 3 ); index++ ) {
            var match = cells[ index ].textContent.trim().match( /^#(\d+)/ );
            if ( match ) {
                return match[ 1 ];
            }
        }
        return null;
    }

    function openActionOrderId() {
        var toggle = document.querySelector( '.directorist-orders-page table.dataviews-view-table tbody tr.dataviews-view-table__row .components-dropdown-menu__toggle[aria-expanded="true"]' );
        return toggle ? orderIdFromRow( toggle.closest( 'tr' ) ) : activeActionOrderId;
    }

    function createMenuLink( href, label, type ) {
        var link = document.createElement( 'a' );
        link.className = 'components-button components-menu-item__button directorist-invoice-menu-item';
        link.href = href;
        link.title = label;
        link.setAttribute( 'role', 'menuitem' );
        if ( 'view' === type ) {
            link.target = '_blank';
            link.rel = 'noopener';
        }
        link.appendChild( createIcon( type ) );
        var text = document.createElement( 'span' );
        text.className = 'components-menu-item__item';
        text.textContent = label;
        link.appendChild( text );
        return link;
    }

    function updateActionMenu() {
        var orderId = openActionOrderId();
        if ( ! orderId ) {
            return;
        }

        document.querySelectorAll( '.components-dropdown-menu__popover .components-dropdown-menu__menu[role="menu"]' ).forEach( function ( menu ) {
            if ( menu.getAttribute( 'aria-label' ) !== config.actionsLabel ) {
                return;
            }
            var firstGroup = menu.querySelector( '.components-menu-group [role="group"]' );
            var firstItem = firstGroup && firstGroup.querySelector( '.components-menu-item__item' );
            if ( ! firstItem || firstItem.textContent.trim() !== config.viewOrderLabel ) {
                return;
            }
            var existingLinks = firstGroup.querySelectorAll( '.directorist-invoice-menu-item' );
            if ( menu.dataset.invoiceOrderId === orderId && ( menu.dataset.invoiceRequest === orderId || ( menu.dataset.invoiceLoaded === orderId && ( existingLinks.length === 2 || menu.dataset.invoiceUnavailable === orderId ) ) ) ) {
                return;
            }

            existingLinks.forEach( function ( link ) { link.remove(); } );
            delete menu.dataset.invoiceLoaded;
            delete menu.dataset.invoiceUnavailable;
            menu.dataset.invoiceOrderId = orderId;
            menu.dataset.invoiceRequest = orderId;
            getLinks( orderId ).then( function ( links ) {
                if ( menu.dataset.invoiceRequest === orderId ) {
                    delete menu.dataset.invoiceRequest;
                }
                if ( ! menu.isConnected || menu.dataset.invoiceOrderId !== orderId || openActionOrderId() !== orderId ) {
                    return;
                }
                menu.dataset.invoiceLoaded = orderId;
                if ( ! links || ! links.view_url || ! links.download_url ) {
                    menu.dataset.invoiceUnavailable = orderId;
                    return;
                }
                firstGroup.appendChild( createMenuLink( links.view_url, config.viewLabel, 'view' ) );
                firstGroup.appendChild( createMenuLink( links.download_url, config.downloadLabel, 'download' ) );
            } );
        } );
    }

    function updateOrderRows() {
        var rows = document.querySelectorAll( '.directorist-user-dashboard-orders .dataviews-view-table tbody tr.dataviews-view-table__row' );
        rows.forEach( function ( row ) {
            var orderId = orderIdFromRow( row );
            var cell = row.querySelector( 'td:last-child' );
            if ( ! orderId || ! cell || row.dataset.invoiceRequest === orderId ) {
                return;
            }
            var existing = cell.querySelector( '.directorist-invoice-order-actions' );
            if ( existing && existing.dataset.orderId === orderId ) {
                return;
            }
            row.dataset.invoiceRequest = orderId;
            getLinks( orderId ).then( function ( links ) {
                delete row.dataset.invoiceRequest;
                if ( ! links || ! row.isConnected || orderIdFromRow( row ) !== orderId ) {
                    return;
                }
                var old = cell.querySelector( '.directorist-invoice-order-actions' );
                if ( old ) {
                    old.remove();
                }
                var actions = createActions( orderId, links );
                if ( actions ) {
                    actions.style.marginLeft = '8px';
                    cell.appendChild( actions );
                }
            } );
        } );
    }

    function update() {
        scheduled = false;
        updateAdmin();
        updateActionMenu();
        updateOrderRows();
    }

    function schedule() {
        if ( ! scheduled ) {
            scheduled = true;
            window.requestAnimationFrame( update );
        }
    }

    function rememberActionOrder( event ) {
        var toggle = event.target.closest && event.target.closest( '.directorist-orders-page table.dataviews-view-table tbody tr.dataviews-view-table__row .components-dropdown-menu__toggle' );
        if ( toggle ) {
            activeActionOrderId = orderIdFromRow( toggle.closest( 'tr' ) );
            schedule();
        }
    }

    document.addEventListener( 'click', rememberActionOrder, true );
    document.addEventListener( 'focusin', rememberActionOrder, true );
    window.addEventListener( 'hashchange', schedule );
    if ( document.body ) {
        new MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true } );
    }
    schedule();
} )();
