jQuery(document).ready(function ($) {
    'use strict';

    console.log("Paigami Checkout initialized");

    // Check if we're in WooCommerce Blocks checkout - only if actual block elements exist
    function isBlocksCheckout() {
        return (typeof wp !== 'undefined' &&
            wp.blocks &&
            wp.blocks.checkout &&
            (document.querySelector('.wc-block-checkout') !== null ||
                document.querySelector('.wc-block-components-checkout-step') !== null ||
                document.querySelector('.wc-block-checkout__payment-method') !== null)
        );
    }

    // Only initialize for classic checkout
    if (isBlocksCheckout()) {
        console.log("Blocks checkout detected, skipping classic init");
        return;
    }

    var paigami = {
        init: function () {
            console.log("Initializing Paigami payment form");
            this.cacheElements();
            this.bindEvents();
            this.bindWooCommerceEvents();
            this.loadInitialData();
        },

        bindWooCommerceEvents: function () {
            var self = this;
            $('body').on('updated_checkout', function () {
                console.log("WooCommerce checkout updated, re-caching elements");
                self.cacheElements();
                self.bindEvents();
                self.updateFormVisibility();
            });

            $('body').on('payment_method_selected', function () {
                console.log("Payment method selected, re-caching elements");
                self.cacheElements();
                self.bindEvents();
                self.updateFormVisibility();
            });
        },

        cacheElements: function () {
            this.$countrySelect = $('#paigami-country');
            this.$walletRows = $('#paigami-wallet-rows');
            this.$walletSelect = $('#paigami-wallet');
            this.$phoneInput = $('#paigami-phone');
            this.$otpInput = $('#paigami-otp');
            this.$walletField = $('#paigami-wallet-field');
            this.$phoneField = $('#paigami-phone-field');
            this.$otpField = $('#paigami-otp-field');
            this.$currencyInfo = $('#paigami-currency-info');
            this.$conversionInfo = $('#paigami-conversion');
            this.$feesInfo = $('#paigami-fees');
            this.$loading = $('#paigami-loading');
            this.$form = $('#paigami-payment-form');

            this.wallets = [];
            this.selectedCountry = null;
            this.selectedWallet = null;
            this.orderTotal = this.getOrderTotal();
            this.shopCurrency = this.getShopCurrency();

            console.log("Elements cached. Order total:", this.orderTotal, "Currency:", this.shopCurrency);
        },

        bindEvents: function () {
            this.$countrySelect.on('change', this.handleCountryChange.bind(this));
            this.$walletRows.on('click', '.paigami-wallet-row', this.handleWalletClick.bind(this));
            this.$phoneInput.on('input', this.handlePhoneInput.bind(this));

            $('form.checkout').on('checkout_place_order_paigami', this.validatePaigamiFields.bind(this));
        },

        loadInitialData: function () {
            this.updateFormVisibility();
        },

        handleCountryChange: function () {
            var countryId = this.$countrySelect.val();

            console.log("Country changed:", countryId);

            if (!countryId) {
                this.resetForm();
                return;
            }

            this.selectedCountry = this.$countrySelect.find('option:selected');
            var currency = this.selectedCountry.data('currency');
            var phonePrefix = this.selectedCountry.data('phone-prefix');

            console.log("Selected country currency:", currency, "Phone prefix:", phonePrefix);

            // Update currency display
            $('#paigami-currency-display').text(currency);
            this.$currencyInfo.show();

            // Auto-fill phone prefix
            if (phonePrefix && !this.$phoneInput.val()) {
                this.$phoneInput.attr('placeholder', phonePrefix + 'XXXXXXXX');
            }

            this.showLoading();

            this.getWallets(countryId)
                .done(this.handleWalletsResponse.bind(this))
                .fail(this.handleAjaxError.bind(this))
                .always(this.hideLoading.bind(this));
        },

        handleWalletsResponse: function (response) {
            console.log("Wallets response:", response);

            if (response.success && response.data) {
                var walletData = response.data.data || response.data;
                this.wallets = Array.isArray(walletData) ? walletData : [];

                console.log("Loaded wallets:", this.wallets.length);

                this.populateWalletRows();
                this.$walletField.show();

                if (this.wallets.length === 1) {
                    var walletId = this.wallets[0].id;
                    console.log("Auto-selecting single wallet:", walletId);
                    this.selectWallet(walletId);
                }
            } else {
                var message = response.message || response.data?.message || 'Failed to load wallets';
                console.error("Failed to load wallets:", message);
                this.showError(message);
            }
        },

        populateWalletRows: function () {
            var self = this;
            var countryCurrency = this.selectedCountry ? this.selectedCountry.data('currency') : '';

            this.$walletRows.empty();

            if (Array.isArray(this.wallets)) {
                this.wallets.forEach(function (wallet) {
                    var walletId = wallet.id;
                    var walletName = wallet.display_name || wallet.name;
                    var logoUrl = wallet.logo_url || wallet.logo;
                    var walletCurrency = wallet.currency.code || wallet.currency || countryCurrency;

                    var $row = $('<div class="paigami-wallet-row"></div>')
                        .attr('data-wallet-id', walletId)
                        .attr('data-otp-required', wallet.otp_required || false)
                        .attr('data-currency', walletCurrency)
                        .attr('data-logo', logoUrl || '')
                        .attr('data-name', walletName);

                    var logoHtml = logoUrl
                        ? '<img src="' + logoUrl + '" alt="' + walletName + '" class="paigami-wallet-logo" />'
                        : '<div class="paigami-wallet-icon">' + walletName.charAt(0) + '</div>';

                    $row.html(
                        '<div class="paigami-wallet-info">' +
                        '<div class="paigami-wallet-logo-container">' + logoHtml + '</div>' +
                        '<div class="paigami-wallet-details">' +
                        '<span class="paigami-wallet-name">' + walletName + '</span>' +
                        '<span class="paigami-wallet-currency">' + walletCurrency + '</span>' +
                        '</div>' +
                        '</div>' +
                        '<div class="paigami-wallet-check"><span class="paigami-check-icon">✓</span></div>'
                    );

                    self.$walletRows.append($row);
                });

                console.log("Populated wallet rows with", this.wallets.length, "options");
            }
        },

        handleWalletClick: function (e) {
            var $row = $(e.currentTarget);
            var walletId = $row.data('wallet-id');

            console.log("Wallet clicked:", walletId);
            this.selectWallet(walletId);
        },

        selectWallet: function (walletId) {
            var $row = this.$walletRows.find('[data-wallet-id="' + walletId + '"]');
            if (!$row.length) return;

            this.$walletRows.find('.paigami-wallet-row').removeClass('selected');
            $row.addClass('selected');

            this.selectedWallet = {
                id: walletId,
                data: function (key) { return $row.data(key); },
                text: function () { return $row.data('name'); },
                val: function () { return walletId; }
            };

            this.$walletSelect.val(walletId);

            var otpRequired = $row.data('otp-required');
            console.log("OTP required:", otpRequired);

            if (otpRequired) {
                this.$otpField.show();
                this.$otpInput.prop('required', true);
            } else {
                this.$otpField.hide();
                this.$otpInput.prop('required', false);
            }

            this.updateWalletLogo($row.data('logo'), $row.data('name'));
            this.$phoneField.show();
            this.getCheckoutOptions();
        },

        handlePhoneInput: function () {
            var phone = this.$phoneInput.val().trim();
            var phonePrefix = this.selectedCountry ? this.selectedCountry.data('phone-prefix') : '';

            // Auto-format phone number
            if (phone && !phone.startsWith('+') && phonePrefix) {
                // Remove any leading zeros
                phone = phone.replace(/^0+/, '');
                // Don't auto-add if user is still typing the prefix
                if (phone.length > 0 && !phonePrefix.includes(phone)) {
                    this.$phoneInput.val(phonePrefix + phone);
                }
            }
        },

        getWallets: function (countryId) {
            console.log("Fetching wallets for country:", countryId);

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

        getCheckoutOptions: function () {
            if (!this.selectedCountry || !this.$walletSelect.val()) {
                return;
            }

            var countryId = this.selectedCountry.val();

            console.log("Fetching checkout options for country:", countryId);

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

        handleCheckoutOptionsResponse: function (response) {
            console.log("Checkout options response:", response);

            if (response.success && response.data) {
                this.updateConversionInfo(response.data);
                this.updateFeesInfo(response.data);
            }
        },

        updateConversionInfo: function (data) {
            var countryCurrency = this.selectedCountry.data('currency');

            if (countryCurrency !== this.shopCurrency && data.converted_amount) {
                var originalAmount = this.formatCurrency(this.orderTotal, this.shopCurrency);
                var convertedAmount = this.formatCurrency(data.converted_amount / 100, countryCurrency);

                this.$conversionInfo.find('.original-amount').text(originalAmount);
                this.$conversionInfo.find('.converted-amount').text(convertedAmount);
                this.$conversionInfo.find('.conversion-rate').text('Approximate conversion');

                this.$conversionInfo.show();
            } else {
                this.$conversionInfo.hide();
            }
        },

        updateFeesInfo: function (data) {
            if (!data.fees || !data.fees.total) {
                this.$feesInfo.hide();
                return;
            }

            var fees = data.fees;
            var totalFees = fees.total / 100;
            var totalAmount = (fees.net_amount + fees.total) / 100;
            var currency = this.selectedCountry.data('currency');

            this.$feesInfo.find('.fees-breakdown').html(
                'Payment fees: <strong>' + this.formatCurrency(totalFees, currency) + '</strong>'
            );

            this.$feesInfo.find('.total-amount').html(
                'Total to pay: <strong>' + this.formatCurrency(totalAmount, currency) + '</strong>'
            );

            this.$feesInfo.show();
        },

        updateWalletLogo: function (logoUrl, walletName) {
            if (logoUrl) {
                $('#wallet-logo').html('<img src="' + logoUrl + '" alt="' + walletName + '" style="max-width: 60px; max-height: 40px;" />');
            } else {
                $('#wallet-logo').empty();
            }
        },

        validatePaigamiFields: function () {
            var isValid = true;

            $('.paigami-error').remove();
            $('.paigami-field-error').removeClass('paigami-field-error');

            if (!this.$countrySelect.val()) {
                this.showFieldError(this.$countrySelect, 'Please select your country');
                isValid = false;
            }

            var $selectedWallet = this.$walletRows.find('.paigami-wallet-row.selected');
            if (!$selectedWallet.length) {
                this.showFieldError(this.$walletRows, 'Please select your mobile money provider');
                isValid = false;
            }

            var phone = this.$phoneInput.val().trim();
            if (!phone) {
                this.showFieldError(this.$phoneInput, paigami_params.phone_required);
                isValid = false;
            } else if (!/^\+[1-9]\d{1,14}$/.test(phone)) {
                this.showFieldError(this.$phoneInput, 'Please enter a valid phone number with country code (e.g., +229XXXXXXXX)');
                isValid = false;
            }

            var otpRequired = $selectedWallet.length ? $selectedWallet.data('otp-required') : false;
            if (otpRequired && !this.$otpInput.val().trim()) {
                this.showFieldError(this.$otpInput, paigami_params.otp_required);
                isValid = false;
            }

            if (!isValid) {
                $('html, body').animate({
                    scrollTop: this.$form.offset().top - 100
                }, 500);
            }

            return isValid;
        },

        showFieldError: function ($field, message) {
            var $error = $('<div class="paigami-error" style="color: #dc3232; margin-top: 5px; font-size: 0.9em;">' + message + '</div>');
            $field.after($error);
            $field.addClass('paigami-field-error');
        },

        showLoading: function () {
            this.$loading.show();
            this.$form.addClass('paigami-loading');
        },

        hideLoading: function () {
            this.$loading.hide();
            this.$form.removeClass('paigami-loading');
        },

        showError: function (message) {
            var $error = $('<div class="woocommerce-error paigami-error">' + message + '</div>');
            this.$form.before($error);
            $('html, body').animate({
                scrollTop: $error.offset().top - 100
            }, 500);
        },

        resetForm: function () {
            this.$walletRows.empty();
            this.$walletField.hide();
            this.$phoneField.hide();
            this.$otpField.hide();
            this.$currencyInfo.hide();
            this.$conversionInfo.hide();
            this.$feesInfo.hide();
            this.$phoneInput.val('');
            this.$otpInput.val('');
            this.$walletSelect.val('');
            this.selectedWallet = null;
            this.wallets = [];
        },

        updateFormVisibility: function () {
            this.$walletField.hide();
            this.$phoneField.hide();
            this.$otpField.hide();
            this.$currencyInfo.hide();
            this.$conversionInfo.hide();
            this.$feesInfo.hide();
        },

        getOrderTotal: function () {
            var $total = $('form.checkout').find('.order-total .amount');
            if ($total.length) {
                var totalText = $total.text().replace(/[^\d.,]/g, '');
                var total = parseFloat(totalText.replace(',', '.'));
                return isNaN(total) ? 0 : total;
            }

            if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.order_total) {
                return parseFloat(wc_checkout_params.order_total);
            }

            return 0;
        },

        getShopCurrency: function () {
            if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.currency) {
                return wc_checkout_params.currency.code || wc_checkout_params.currency;
            }

            var $currency = $('form.checkout').find('.woocommerce-Price-currencySymbol');
            if ($currency.length) {
                return $currency.first().text().trim();
            }

            return 'USD';
        },

        formatCurrency: function (amount, currency) {
            currency = currency || this.shopCurrency;

            // Currencies without decimal places
            if (['XOF', 'XAF', 'CDF', 'RWF', 'BIF', 'UGX'].includes(currency)) {
                return currency + ' ' + Math.round(amount).toLocaleString('en-US', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0
                });
            }

            return currency + ' ' + parseFloat(amount).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        },

        handleAjaxError: function (xhr, status, error) {
            console.error("AJAX Error:", xhr, status, error);

            var message = 'An error occurred';

            if (xhr.responseJSON) {
                message = xhr.responseJSON.message || xhr.responseJSON.data?.message || message;
            } else if (xhr.responseText) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    message = response.message || response.data?.message || message;
                } catch (e) {
                    message = 'Server error: ' + (xhr.status || 'Unknown');
                }
            }

            this.showError(message);
        }
    };

    // Initialize when document is ready
    paigami.init();

    // Make paigami available globally
    window.paigami = paigami;
});