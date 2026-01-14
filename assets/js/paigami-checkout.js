jQuery(document).ready(function($) {
    'use strict';
    
    // Check if we're in WooCommerce Blocks checkout
    function isBlocksCheckout() {
        return document.querySelector('.wc-block-checkout') !== null || 
               typeof wp !== 'undefined' && 
               wp.blocks && 
               wp.blocks.checkout ||
               document.querySelector('.wc-block-components-checkout-step') !== null;
    }
    
    // Only initialize for classic checkout
    if (isBlocksCheckout()) {
        return; // Don't initialize classic checkout in blocks context
    }
    
    var paigami = {
        init: function() {
            this.cacheElements();
            this.bindEvents();
            this.loadInitialData();
        },
        
        cacheElements: function() {
            this.$countrySelect = $('#paigami-country');
            this.$walletSelect = $('#paigami-wallet');
            this.$phoneInput = $('#paigami-phone');
            this.$otpInput = $('#paigami-otp');
            this.$walletField = $('#paigami-wallet-field');
            this.$phoneField = $('#paigami-phone-field');
            this.$otpField = $('#paigami-otp-field');
            this.$conversionInfo = $('#paigami-conversion');
            this.$feesInfo = $('#paigami-fees');
            this.$loading = $('#paigami-loading');
            this.$form = $('#paigami-payment-form');
            
            this.wallets = {};
            this.selectedCountry = null;
            this.selectedWallet = null;
            this.orderTotal = this.getOrderTotal();
            this.shopCurrency = this.getShopCurrency();
        },
        
        bindEvents: function() {
            this.$countrySelect.on('change', this.handleCountryChange.bind(this));
            this.$walletSelect.on('change', this.handleWalletChange.bind(this));
            this.$phoneInput.on('input', this.handlePhoneInput.bind(this));
            
            // Handle WooCommerce checkout form events
            $('form.checkout').on('checkout_place_order_paigami', this.validatePaigamiFields.bind(this));
        },
        
        loadInitialData: function() {
            // Countries are already loaded in the form via PHP
            this.updateFormVisibility();
        },
        
        handleCountryChange: function() {
            var countryId = this.$countrySelect.val();
            
            if (!countryId) {
                this.resetForm();
                return;
            }
            
            this.selectedCountry = this.$countrySelect.find('option:selected');
            this.showLoading();
            
            this.getWallets(countryId)
                .done(this.handleWalletsResponse.bind(this))
                .fail(this.handleAjaxError.bind(this))
                .always(this.hideLoading.bind(this));
        },
        
        handleWalletsResponse: function(response) {
            if (response.success && response.data) {
                this.wallets = response.data;
                this.populateWalletSelect();
                this.$walletField.show();
                
                // Get checkout options for conversion info
                this.getCheckoutOptions();
            } else {
                this.showError('Failed to load wallets: ' + (response.message || 'Unknown error'));
            }
        },
        
        populateWalletSelect: function() {
            this.$walletSelect.empty().append('<option value="">' + paigami_params.select_wallet + '</option>');
            
            if (Array.isArray(this.wallets)) {
                this.wallets.forEach(function(wallet) {
                    var $option = $('<option></option>')
                        .attr('value', wallet.id || wallet.wallet_id)
                        .text(wallet.name || wallet.wallet_name)
                        .data('otp-required', wallet.otp_required || false)
                        .data('currency', wallet.currency || '');
                    
                    this.$walletSelect.append($option);
                }.bind(this));
            }
        },
        
        handleWalletChange: function() {
            var walletId = this.$walletSelect.val();
            
            if (!walletId) {
                this.$phoneField.hide();
                this.$otpField.hide();
                return;
            }
            
            this.selectedWallet = this.$walletSelect.find('option:selected');
            this.$phoneField.show();
            
            // Show OTP field if required
            var otpRequired = this.selectedWallet.data('otp-required');
            if (otpRequired) {
                this.$otpField.show();
                this.$otpInput.prop('required', true);
            } else {
                this.$otpField.hide();
                this.$otpInput.prop('required', false);
            }
            
            this.updateWalletLogo();
            this.getCheckoutOptions();
        },
        
        handlePhoneInput: function() {
            var phone = this.$phoneInput.val().trim();
            
            // Auto-format phone number
            if (phone && !phone.startsWith('+')) {
                var countryCode = this.selectedCountry.data('country-code') || '';
                if (countryCode) {
                    this.$phoneInput.val(countryCode + phone);
                }
            }
        },
        
        getWallets: function(countryId) {
            return $.ajax({
                url: paigami_params.ajax_url,
                method: 'POST',
                data: {
                    action: 'paigami_get_wallets',
                    nonce: paigami_params.nonce,
                    country_id: countryId
                }
            });
        },
        
        getCheckoutOptions: function() {
            if (!this.selectedCountry || !this.selectedWallet) {
                return;
            }
            
            var countryId = this.selectedCountry.val();
            var walletCurrency = this.selectedWallet.data('currency') || this.selectedCountry.data('currency');
            
            this.showLoading();
            
            $.ajax({
                url: paigami_params.ajax_url,
                method: 'POST',
                data: {
                    action: 'paigami_get_checkout_options',
                    nonce: paigami_params.nonce,
                    country_id: countryId,
                    amount: this.orderTotal,
                    currency: this.shopCurrency
                }
            })
            .done(this.handleCheckoutOptionsResponse.bind(this))
            .fail(this.handleAjaxError.bind(this))
            .always(this.hideLoading.bind(this));
        },
        
        handleCheckoutOptionsResponse: function(response) {
            if (response.success && response.data) {
                this.updateConversionInfo(response.data);
                this.updateFeesInfo(response.data);
            }
        },
        
        updateConversionInfo: function(data) {
            var countryCurrency = this.selectedCountry.data('currency');
            
            if (countryCurrency !== this.shopCurrency) {
                var originalAmount = this.formatCurrency(this.orderTotal, this.shopCurrency);
                var convertedAmount = this.formatCurrency(this.orderTotal, countryCurrency);
                
                this.$conversionInfo.find('.original-amount').text(originalAmount);
                this.$conversionInfo.find('.converted-amount').text(convertedAmount);
                this.$conversionInfo.find('.conversion-rate').text('Approximate conversion rate');
                
                this.$conversionInfo.show();
            } else {
                this.$conversionInfo.hide();
            }
        },
        
        updateFeesInfo: function(data) {
            var fees = data.fees;
            
            if (fees && fees.total > 0) {
                var feeBreakdown = '';
                var totalFees = fees.total / 100; // Convert from cents
                
                if (fees.breakdown) {
                    var breakdown = [];
                    if (fees.breakdown.operator_fee) breakdown.push('Operator: ' + this.formatCurrency(fees.breakdown.operator_fee / 100));
                    if (fees.breakdown.aggregator_fee) breakdown.push('Gateway: ' + this.formatCurrency(fees.breakdown.aggregator_fee / 100));
                    if (fees.breakdown.platform_fee) breakdown.push('Platform: ' + this.formatCurrency(fees.breakdown.platform_fee / 100));
                    if (fees.breakdown.tax) breakdown.push('Tax: ' + this.formatCurrency(fees.breakdown.tax / 100));
                    
                    feeBreakdown = breakdown.join(' + ');
                }
                
                var totalAmount = this.formatCurrency((fees.net_amount + fees.total) / 100);
                
                this.$feesInfo.find('.fees-breakdown').html('Fees: ' + feeBreakdown + ' = <strong>' + this.formatCurrency(totalFees) + '</strong>');
                this.$feesInfo.find('.total-amount').html('Total to pay: <strong>' + totalAmount + '</strong>');
                
                this.$feesInfo.show();
            } else {
                this.$feesInfo.hide();
            }
        },
        
        updateWalletLogo: function() {
            var walletId = this.$walletSelect.val();
            var wallet = this.wallets.find(function(w) {
                return (w.id || w.wallet_id) === walletId;
            });
            
            if (wallet && wallet.logo_url) {
                $('#wallet-logo').html('<img src="' + wallet.logo_url + '" alt="' + wallet.name + '" />');
            } else {
                $('#wallet-logo').empty();
            }
        },
        
        validatePaigamiFields: function() {
            var isValid = true;
            
            // Remove previous error messages
            $('.paigami-error').remove();
            
            // Validate country
            if (!this.$countrySelect.val()) {
                this.showFieldError(this.$countrySelect, 'Please select your country');
                isValid = false;
            }
            
            // Validate wallet
            if (!this.$walletSelect.val()) {
                this.showFieldError(this.$walletSelect, 'Please select your mobile money provider');
                isValid = false;
            }
            
            // Validate phone
            var phone = this.$phoneInput.val().trim();
            if (!phone) {
                this.showFieldError(this.$phoneInput, paigami_params.phone_required);
                isValid = false;
            } else if (!/^\+[1-9]\d{1,14}$/.test(phone)) {
                this.showFieldError(this.$phoneInput, 'Please enter a valid phone number with country code');
                isValid = false;
            }
            
            // Validate OTP if required
            var otpRequired = this.selectedWallet ? this.selectedWallet.data('otp-required') : false;
            if (otpRequired && !this.$otpInput.val().trim()) {
                this.showFieldError(this.$otpInput, paigami_params.otp_required);
                isValid = false;
            } else if (otpRequired && !/^\d{6}$/.test(this.$otpInput.val())) {
                this.showFieldError(this.$otpInput, 'Please enter a valid 6-digit OTP code');
                isValid = false;
            }
            
            return isValid;
        },
        
        showFieldError: function($field, message) {
            var $error = $('<div class="paigami-error">' + message + '</div>');
            $field.after($error);
            $field.addClass('paigami-field-error');
        },
        
        showLoading: function() {
            this.$loading.show();
            this.$form.addClass('paigami-loading');
        },
        
        hideLoading: function() {
            this.$loading.hide();
            this.$form.removeClass('paigami-loading');
        },
        
        showError: function(message) {
            var $error = $('<div class="woocommerce-error paigami-error">' + message + '</div>');
            this.$form.before($error);
            $('html, body').animate({
                scrollTop: $error.offset().top - 100
            }, 500);
        },
        
        resetForm: function() {
            this.$walletSelect.empty().append('<option value="">' + paigami_params.select_wallet + '</option>');
            this.$walletField.hide();
            this.$phoneField.hide();
            this.$otpField.hide();
            this.$conversionInfo.hide();
            this.$feesInfo.hide();
            this.selectedWallet = null;
        },
        
        updateFormVisibility: function() {
            // Initial state - only show country selection
            this.$walletField.hide();
            this.$phoneField.hide();
            this.$otpField.hide();
            this.$conversionInfo.hide();
            this.$feesInfo.hide();
        },
        
        getOrderTotal: function() {
            // Try to get total from WooCommerce
            var $total = $('form.checkout').find('.order-total .amount');
            if ($total.length) {
                var totalText = $total.text().replace(/[^\d.,]/g, '');
                return parseFloat(totalText.replace(',', '.'));
            }
            
            // Fallback for cart page
            if (typeof wc_cart_params !== 'undefined') {
                return wc_cart_params.cart_total || 0;
            }
            
            return 0;
        },
        
        getShopCurrency: function() {
            // Try to get currency from WooCommerce
            var $currency = $('form.checkout').find('.woocommerce-Price-currencySymbol');
            if ($currency.length) {
                return $currency.first().text().trim();
            }
            
            // Fallback
            if (typeof wc_cart_params !== 'undefined') {
                return wc_cart_params.currency || 'USD';
            }
            
            return 'USD';
        },
        
        formatCurrency: function(amount, currency) {
            currency = currency || this.shopCurrency;
            
            // Simple formatting - can be enhanced based on currency
            if (['XOF', 'XAF', 'CDF', 'RWF', 'BIF'].includes(currency)) {
                // No decimal places for these currencies
                return currency + ' ' + Math.round(amount).toLocaleString();
            } else {
                return currency + ' ' + amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        },
        
        handleAjaxError: function(xhr, status, error) {
            var message = 'An error occurred';
            
            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            } else if (xhr.responseText) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    message = response.message || message;
                } catch (e) {
                    message = 'Server error: ' + (xhr.status || 'Unknown');
                }
            }
            
            this.showError(message);
            if (typeof paigami_params !== 'undefined' && paigami_params.api_url && window.console) {
                console.log('Paigami AJAX Error: ' + message);
            }
        }
    };
    
    // Initialize when document is ready
    paigami.init();
    
    // Make paigami available globally for WooCommerce
    window.paigami = paigami;
});