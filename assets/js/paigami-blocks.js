( function () {
    'use strict';
    
    // Get dependencies safely
    const wpElement = window.wp && window.wp.element;
    const wpComponents = window.wp && window.wp.components;
    const wpHtmlEntities = window.wp && window.wp.htmlEntities;
    
    // Check for multiple possible wcBlocksCheckout locations
    let wcBlocksCheckout = null;
    if ( window.wc && window.wc.wcBlocksCheckout ) {
        wcBlocksCheckout = window.wc.wcBlocksCheckout;
    } else if ( window.wc && window.wc.blocksCheckout ) {
        wcBlocksCheckout = window.wc.blocksCheckout;
    } else if ( window.wcBlocksCheckout ) {
        wcBlocksCheckout = window.wcBlocksCheckout;
    }
    
    // Check if required dependencies are available
    if ( !wpElement || !wpComponents ) {
        console.error( 'Paigami: Required WordPress dependencies not available' );
        return;
    }
    
    const { createElement: el, useState, useEffect, useCallback } = wpElement;
    const { SelectControl, TextControl, Notice, Spinner, __experimentalInputControl: InputControl } = wpComponents;
    
    // Safely access with fallbacks
    const PaymentMethodLabel = wcBlocksCheckout && wcBlocksCheckout.PaymentMethodLabel ? wcBlocksCheckout.PaymentMethodLabel : function( props ) {
        return el( 'span', {}, props.text || 'Paigami' );
    };
    
    const usePaymentMethodDataContext = wcBlocksCheckout && wcBlocksCheckout.usePaymentMethodDataContext ? wcBlocksCheckout.usePaymentMethodDataContext : function() {
        return {
            cartTotals: {
                total_price: 0,
                currency_code: 'USD'
            }
        };
    };
    
    const decodeEntities = wpHtmlEntities && wpHtmlEntities.decodeEntities ? wpHtmlEntities.decodeEntities : function( text ) {
        return text;
    };
    
    const PaigamiPaymentMethod = ( { billing, shippingData, eventRegistration, emitResponse } ) => {
        const { onPaymentSetup } = eventRegistration;
        const [paymentData, setPaymentData] = useState( {
            country: '',
            wallet: '',
            phone: '',
            otp: '',
            wallets: [],
            selectedCountryData: null,
            selectedWalletData: null,
            loading: false,
            error: '',
            checkoutOptions: null
        } );
        
        const { cartTotals } = usePaymentMethodDataContext();
        const paymentMethodData = window.wc.wcSettings.getPaymentMethodData( 'paigami' );
        
        const fetchWallets = useCallback( async ( countryId ) => {
            setPaymentData( prev => ( { ...prev, loading: true, error: '', wallet: '', wallets: [] } ) );
            
            try {
                const response = await fetch( paymentMethodData.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams( {
                        action: 'paigami_get_wallets',
                        nonce: paymentMethodData.nonce,
                        country_id: countryId
                    } )
                } );
                
                const data = await response.json();
                
                if ( data.success ) {
                    setPaymentData( prev => ( {
                        ...prev,
                        wallets: data.data || [],
                        loading: false
                    } ) );
                } else {
                    setPaymentData( prev => ( {
                        ...prev,
                        error: data.message || 'Failed to load wallets',
                        loading: false
                    } ) );
                }
            } catch ( error ) {
                setPaymentData( prev => ( {
                    ...prev,
                    error: error.message || 'Network error',
                    loading: false
                } ) );
            }
        }, [paymentMethodData.ajax_url, paymentMethodData.nonce] );
        
        const fetchCheckoutOptions = useCallback( async ( countryId, walletId ) => {
            if ( !cartTotals?.total_price ) return;
            
            try {
                const response = await fetch( paymentMethodData.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams( {
                        action: 'paigami_get_checkout_options',
                        nonce: paymentMethodData.nonce,
                        country_id: countryId,
                        amount: cartTotals.total_price,
                        currency: cartTotals.currency_code
                    } )
                } );
                
                const data = await response.json();
                
                if ( data.success ) {
                    setPaymentData( prev => ( {
                        ...prev,
                        checkoutOptions: data.data
                    } ) );
                }
            } catch ( error ) {
                console.error( 'Checkout options error:', error );
            }
        }, [paymentMethodData.ajax_url, paymentMethodData.nonce, cartTotals] );
        
        const handleCountryChange = useCallback( ( value ) => {
            const countryData = paymentMethodData.countries.find( c => c.id === value );
            
            setPaymentData( prev => ( {
                ...prev,
                country: value,
                selectedCountryData: countryData,
                wallet: '',
                selectedWalletData: null,
                otp: '',
                checkoutOptions: null
            } ) );
            
            if ( value ) {
                fetchWallets( value );
            }
        }, [paymentMethodData.countries, fetchWallets] );
        
        const handleWalletChange = useCallback( ( value ) => {
            const walletData = paymentData.wallets.find( w => w.id === value || w.wallet_id === value );
            
            setPaymentData( prev => ( {
                ...prev,
                wallet: value,
                selectedWalletData: walletData,
                otp: walletData?.otp_required ? '' : prev.otp
            } ) );
            
            if ( value && paymentData.selectedCountryData ) {
                fetchCheckoutOptions( paymentData.selectedCountryData.id, value );
            }
        }, [paymentData.wallets, paymentData.selectedCountryData, fetchCheckoutOptions] );
        
        const getCountryOptions = () => {
            return paymentMethodData.countries.map( country => ( {
                label: `${ country.name } (${ country.currency })`,
                value: country.id
            } ) );
        };
        
        const getWalletOptions = () => {
            return paymentData.wallets.map( wallet => ( {
                label: wallet.name || wallet.wallet_name,
                value: wallet.id || wallet.wallet_id
            } ) );
        };
        
        const renderConversionInfo = () => {
            if ( !paymentData.checkoutOptions ) return null;
            
            const countryCurrency = paymentData.selectedCountryData?.currency;
            const shopCurrency = cartTotals?.currency_code;
            
            if ( countryCurrency !== shopCurrency ) {
                return el(
                    'div',
                    { className: 'paigami-conversion-info' },
                    el(
                        'div',
                        { className: 'conversion-amount' },
                        `${ shopCurrency } ${ cartTotals?.total_price || 0 } → ${ countryCurrency } ${ cartTotals?.total_price || 0 }`
                    ),
                    el(
                        'small',
                        { className: 'conversion-rate' },
                        paymentMethodData.strings.conversion_info
                    )
                );
            }
            
            return null;
        };
        
        const renderFeesInfo = () => {
            if ( !paymentData.checkoutOptions?.fees?.total ) return null;
            
            const fees = paymentData.checkoutOptions.fees;
            const totalFees = fees.total / 100;
            const totalAmount = ( fees.net_amount + fees.total ) / 100;
            
            return el(
                'div',
                { className: 'paigami-fees-info' },
                el(
                    'div',
                    { className: 'fees-breakdown' },
                    `${ paymentMethodData.strings.fees_info }: ${ paymentMethodData.currency || '' } ${ totalFees.toFixed( 2 ) }`
                ),
                el(
                    'div',
                    { className: 'total-amount' },
                    `${ paymentMethodData.strings.total_amount }: ${ paymentMethodData.currency || '' } ${ totalAmount.toFixed( 2 ) }`
                )
            );
        };
        
        useEffect( () => {
            const unsubscribe = onPaymentSetup( async () => {
                if ( !paymentData.country || !paymentData.wallet || !paymentData.phone ) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: paymentMethodData.strings.country_required
                    };
                }
                
                const phoneRegex = /^\+[1-9]\d{1,14}$/;
                if ( !phoneRegex.test( paymentData.phone ) ) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: paymentMethodData.strings.invalid_phone
                    };
                }
                
                const otpRequired = paymentData.selectedWalletData?.otp_required;
                if ( otpRequired && !paymentData.otp ) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: paymentMethodData.strings.otp_required
                    };
                }
                
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            paigami_country: paymentData.country,
                            paigami_wallet: paymentData.wallet,
                            paigami_phone: paymentData.phone,
                            paigami_otp: paymentData.otp,
                        },
                    }
                };
            } );
            
            return () => unsubscribe();
        }, [
            paymentData,
            onPaymentSetup,
            emitResponse,
            paymentMethodData
        ] );
        
        return el(
            'div',
            { className: 'paigami-blocks-payment-form' },
            paymentMethodData.description && el(
                'p',
                { className: 'paigami-description' },
                decodeEntities( paymentMethodData.description )
            ),
            
            el(
                SelectControl,
                {
                    label: paymentMethodData.strings.select_country,
                    value: paymentData.country,
                    options: [
                        { label: paymentMethodData.strings.select_country, value: '' },
                        ...getCountryOptions()
                    ],
                    onChange: handleCountryChange,
                    disabled: paymentData.loading
                }
            ),
            
            paymentData.wallets.length > 0 && el(
                SelectControl,
                {
                    label: paymentMethodData.strings.select_wallet,
                    value: paymentData.wallet,
                    options: [
                        { label: paymentMethodData.strings.select_wallet, value: '' },
                        ...getWalletOptions()
                    ],
                    onChange: handleWalletChange,
                    disabled: paymentData.loading
                }
            ),
            
            paymentData.wallet && el(
                'div',
                { className: 'paigami-phone-field' },
                el(
                    InputControl,
                    {
                        label: paymentMethodData.strings.phone_label,
                        placeholder: paymentMethodData.strings.phone_placeholder,
                        value: paymentData.phone,
                        onChange: ( value ) => setPaymentData( prev => ( { ...prev, phone: value } ) ),
                        disabled: paymentData.loading
                    }
                ),
                el(
                    'small',
                    { className: 'paigami-help' },
                    paymentMethodData.strings.phone_help
                )
            ),
            
            paymentData.selectedWalletData?.otp_required && el(
                'div',
                { className: 'paigami-otp-field' },
                el(
                    InputControl,
                    {
                        label: paymentMethodData.strings.otp_label,
                        placeholder: paymentMethodData.strings.otp_placeholder,
                        value: paymentData.otp,
                        onChange: ( value ) => setPaymentData( prev => ( { ...prev, otp: value } ) ),
                        maxLength: 6,
                        disabled: paymentData.loading
                    }
                ),
                el(
                    'small',
                    { className: 'paigami-help' },
                    paymentMethodData.strings.otp_help
                )
            ),
            
            renderConversionInfo(),
            renderFeesInfo(),
            
            paymentData.error && el(
                Notice,
                { status: 'error', isDismissible: false },
                paymentData.error
            ),
            
            paymentData.loading && el(
                'div',
                { className: 'paigami-loading' },
                el( Spinner ),
                el( 'span', {}, paymentMethodData.strings.processing )
            )
        );
    };
    
    const PaigamiPaymentMethodLabel = ( { ...props } ) => {
        const paymentMethodData = window.wc.wcSettings.getPaymentMethodData( 'paigami' );
        
        return el(
            PaymentMethodLabel,
            {
                ...props,
                text: paymentMethodData?.title || 'Mobile Money',
                icons: paymentMethodData?.icon ? [
                    el( 'img', {
                        src: paymentMethodData.icon,
                        alt: 'Paigami',
                        style: { width: '24px', height: '24px' }
                    } )
                ] : []
            }
        );
    };
    
    const PaigamiPaymentMethodContent = ( props ) => {
        const { billing } = props;
        
        return el( PaigamiPaymentMethod, {
            ...props,
            billing,
            shippingData: {},
            eventRegistration: props.eventRegistration,
            emitResponse: props.emitResponse
        } );
    };
    
    // Register the payment method with WooCommerce Blocks
    if ( window.wc && window.wc.wcBlocksRegistry && window.wc.wcSettings ) {
        try {
            window.wc.wcBlocksRegistry.registerPaymentMethod( {
                name: 'paigami',
                label: PaigamiPaymentMethodLabel,
                content: PaigamiPaymentMethodContent,
                edit: () => null,
                canMakePayment: () => true,
                ariaLabel: 'Pay with Paigami Unified Payments',
                supports: {
                    features: window.wc.wcSettings.getPaymentMethodData( 'paigami' )?.supports || []
                }
            } );
        } catch ( error ) {
            console.error( 'Paigami: Failed to register payment method', error );
        }
    } else {
        console.error( 'Paigami: WooCommerce Blocks registry not available' );
    }
    
} )();