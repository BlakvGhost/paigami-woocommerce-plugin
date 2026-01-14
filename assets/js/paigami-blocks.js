(function () {
    'use strict';

    // Vérifier que les dépendances WooCommerce Blocks sont disponibles
    if (typeof window.wc === 'undefined' ||
        typeof window.wc.wcBlocksRegistry === 'undefined' ||
        typeof window.wc.wcSettings === 'undefined') {
        console.warn('Paigami: WooCommerce Blocks dependencies not available');
        return;
    }

    // Vérifier que React est disponible
    if (typeof window.wp === 'undefined' ||
        typeof window.wp.element === 'undefined') {
        console.warn('Paigami: WordPress element (React) not available');
        return;
    }

    const { createElement: el, useState, useEffect, useCallback } = window.wp.element;
    const { __ } = window.wp.i18n || { __: (text) => text };

    // Récupérer les données de configuration du paiement
    const settings = window.wc.wcSettings.getSetting('paigami_data', {});
    const label = settings.title || __('Mobile Money', 'paigami-woocommerce');

    /**
     * Composant pour le label de la méthode de paiement
     */
    const PaigamiLabel = (props) => {
        const { PaymentMethodLabel } = window.wc.wcBlocksCheckout || {};

        if (PaymentMethodLabel) {
            return el(PaymentMethodLabel, { text: label });
        }

        // Fallback si PaymentMethodLabel n'est pas disponible
        return el('span', { className: 'wc-block-components-payment-method-label' }, label);
    };

    /**
     * Composant principal pour le formulaire de paiement
     */
    const PaigamiPaymentMethod = (props) => {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentSetup } = eventRegistration;

        // État du formulaire
        const [paymentData, setPaymentData] = useState({
            country: '',
            wallet: '',
            phone: '',
            otp: '',
            wallets: [],
            loading: false,
            error: null
        });

        /**
         * Charger les wallets pour un pays
         */
        const loadWallets = useCallback(async (countryId) => {
            if (!countryId) {
                setPaymentData(prev => ({ ...prev, wallets: [], wallet: '' }));
                return;
            }

            setPaymentData(prev => ({ ...prev, loading: true, error: null }));

            try {
                const formData = new FormData();
                formData.append('action', 'paigami_get_wallets');
                formData.append('nonce', settings.nonce);
                formData.append('country_id', countryId);

                const response = await fetch(settings.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success && data.data) {
                    setPaymentData(prev => ({
                        ...prev,
                        wallets: Array.isArray(data.data) ? data.data : [],
                        loading: false
                    }));
                } else {
                    throw new Error(data.message || 'Failed to load wallets');
                }
            } catch (error) {
                setPaymentData(prev => ({
                    ...prev,
                    loading: false,
                    error: error.message
                }));
            }
        }, [settings.ajax_url, settings.nonce]);

        /**
         * Gérer le changement de pays
         */
        const handleCountryChange = (event) => {
            const countryId = event.target.value;
            setPaymentData(prev => ({
                ...prev,
                country: countryId,
                wallet: '',
                wallets: []
            }));

            if (countryId) {
                loadWallets(countryId);
            }
        };

        /**
         * Gérer le changement de wallet
         */
        const handleWalletChange = (event) => {
            const walletId = event.target.value;
            const wallet = paymentData.wallets.find(w =>
                String(w.id || w.wallet_id) === String(walletId)
            );

            setPaymentData(prev => ({
                ...prev,
                wallet: walletId,
                otp: wallet?.otp_required ? prev.otp : ''
            }));
        };

        /**
         * Valider les données avant le paiement
         */
        useEffect(() => {
            const unsubscribe = onPaymentSetup(async () => {
                // Validation du pays
                if (!paymentData.country) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: settings.strings?.country_required || 'Please select your country'
                    };
                }

                // Validation du wallet
                if (!paymentData.wallet) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: settings.strings?.wallet_required || 'Please select your provider'
                    };
                }

                // Validation du téléphone
                if (!paymentData.phone) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: settings.strings?.phone_required || 'Phone number is required'
                    };
                }

                const phoneRegex = /^\+[1-9]\d{1,14}$/;
                if (!phoneRegex.test(paymentData.phone)) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: settings.strings?.invalid_phone || 'Invalid phone number format'
                    };
                }

                // Validation OTP si requis
                const wallet = paymentData.wallets.find(w =>
                    String(w.id || w.wallet_id) === String(paymentData.wallet)
                );

                if (wallet?.otp_required && !paymentData.otp) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: settings.strings?.otp_required || 'OTP code is required'
                    };
                }

                // Données valides
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            paigami_country: paymentData.country,
                            paigami_wallet: paymentData.wallet,
                            paigami_phone: paymentData.phone,
                            paigami_otp: paymentData.otp
                        }
                    }
                };
            });

            return unsubscribe;
        }, [paymentData, onPaymentSetup, emitResponse, settings]);

        // Récupérer le wallet sélectionné
        const selectedWallet = paymentData.wallets.find(w =>
            String(w.id || w.wallet_id) === String(paymentData.wallet)
        );

        return el(
            'div',
            { className: 'paigami-blocks-payment-form' },

            // Description
            settings.description && el(
                'p',
                { className: 'wc-block-components-payment-method-content__description' },
                settings.description
            ),

            // Sélection du pays
            el(
                'div',
                { className: 'wc-block-components-text-input' },
                el('label', { htmlFor: 'paigami-country' },
                    settings.strings?.select_country || 'Select your country'
                ),
                el(
                    'select',
                    {
                        id: 'paigami-country',
                        className: 'wc-block-components-select',
                        value: paymentData.country,
                        onChange: handleCountryChange,
                        disabled: paymentData.loading
                    },
                    el('option', { value: '' },
                        settings.strings?.select_country || 'Select your country'
                    ),
                    settings.countries && Array.isArray(settings.countries) &&
                    settings.countries.map(country =>
                        el('option', {
                            key: country.id,
                            value: country.id
                        }, `${country.name} (${country.currency})`)
                    )
                )
            ),

            // Sélection du wallet
            paymentData.wallets.length > 0 && el(
                'div',
                { className: 'wc-block-components-text-input' },
                el('label', { htmlFor: 'paigami-wallet' },
                    settings.strings?.select_wallet || 'Select your provider'
                ),
                el(
                    'select',
                    {
                        id: 'paigami-wallet',
                        className: 'wc-block-components-select',
                        value: paymentData.wallet,
                        onChange: handleWalletChange,
                        disabled: paymentData.loading
                    },
                    el('option', { value: '' },
                        settings.strings?.select_wallet || 'Select your provider'
                    ),
                    paymentData.wallets.map(wallet =>
                        el('option', {
                            key: wallet.id || wallet.wallet_id,
                            value: wallet.id || wallet.wallet_id
                        }, wallet.name || wallet.wallet_name)
                    )
                )
            ),

            // Champ téléphone
            paymentData.wallet && el(
                'div',
                { className: 'wc-block-components-text-input' },
                el('label', { htmlFor: 'paigami-phone' },
                    settings.strings?.phone_label || 'Phone Number'
                ),
                el('input', {
                    id: 'paigami-phone',
                    type: 'tel',
                    className: 'wc-block-components-text-input__input',
                    placeholder: settings.strings?.phone_placeholder || '+229XXXXXXXX',
                    value: paymentData.phone,
                    onChange: (e) => setPaymentData(prev => ({
                        ...prev,
                        phone: e.target.value
                    })),
                    disabled: paymentData.loading
                }),
                el('small', { className: 'wc-block-components-validation-error' },
                    settings.strings?.phone_help || 'Enter your phone number with country code'
                )
            ),

            // Champ OTP si requis
            selectedWallet?.otp_required && el(
                'div',
                { className: 'wc-block-components-text-input' },
                el('label', { htmlFor: 'paigami-otp' },
                    settings.strings?.otp_label || 'OTP Code'
                ),
                el('input', {
                    id: 'paigami-otp',
                    type: 'text',
                    className: 'wc-block-components-text-input__input',
                    placeholder: settings.strings?.otp_placeholder || '123456',
                    value: paymentData.otp,
                    onChange: (e) => setPaymentData(prev => ({
                        ...prev,
                        otp: e.target.value
                    })),
                    maxLength: 6,
                    disabled: paymentData.loading
                }),
                el('small', { className: 'wc-block-components-validation-error' },
                    settings.strings?.otp_help || 'Enter the 6-digit code sent to your phone'
                )
            ),

            // Message d'erreur
            paymentData.error && el(
                'div',
                { className: 'wc-block-components-validation-error', role: 'alert' },
                paymentData.error
            ),

            // Indicateur de chargement
            paymentData.loading && el(
                'div',
                { className: 'wc-block-components-spinner' },
                settings.strings?.loading || 'Loading...'
            )
        );
    };

    /**
     * Configuration de la méthode de paiement
     */
    const PaigamiPaymentMethodConfig = {
        name: 'paigami',
        label: el(PaigamiLabel),
        content: el(PaigamiPaymentMethod),
        edit: el(PaigamiPaymentMethod),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings.supports || ['products']
        }
    };

    // Enregistrer la méthode de paiement
    window.wc.wcBlocksRegistry.registerPaymentMethod(PaigamiPaymentMethodConfig);

})();