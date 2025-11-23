(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Check if product data is available
        if (typeof typesenseProductData === 'undefined') {
            return;
        }
        
        // Save product to viewed history
        saveViewedProduct(typesenseProductData);
        
        function saveViewedProduct(product) {
            if (!product || !product.id) return;
            
            try {
                let viewed = JSON.parse(localStorage.getItem('typesense_viewed_products')) || [];
                
                // Remove existing if present
                viewed = viewed.filter(item => item.id !== product.id);
                
                // Add to start
                viewed.unshift(product);
                
                // Limit to 6 products
                viewed = viewed.slice(0, 6);
                
                localStorage.setItem('typesense_viewed_products', JSON.stringify(viewed));
            } catch (e) {
                console.error('Typesense: Failed to save viewed product', e);
            }
        }
    });
})(jQuery);

