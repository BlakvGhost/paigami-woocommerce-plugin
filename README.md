# Paigami WooCommerce Payment Gateway

Un plugin WooCommerce complet pour accepter les paiements mobile money à travers l'Afrique via l'API Paigami.

## 🌍 Fonctionnalités

### Support Multi-Pays
- **12 pays africains supportés**: Bénin, Côte d'Ivoire, Sénégal, Togo, Nigeria, Ghana, Kenya, Ouganda, Tanzanie, Zambie, Malawi, Burundi
- **20+ opérateurs mobile money**: MTN MoMo, Moov Money, Orange Money, M-Pesa, Wave, Paga, OPay, etc.

### Expérience Utilisateur Avancée
- **Interface de checkout intuitive** avec sélection progressive
- **Conversion automatique des devises** en temps réel
- **Affichage transparent des frais** par opérateur
- **Support OTP** pour les opérateurs qui le requièrent
- **Design responsive** et accessible

### Sécurité et Fiabilité
- **Webhooks sécurisés** avec signature HMAC
- **Gestion des erreurs** robuste
- **Mode test intégré** pour le développement
- **Logging détaillé** pour le débogage
- **Compatible HPS** (High Performance Order Storage)

## 📦 Installation

1. Téléchargez le plugin et placez-le dans `/wp-content/plugins/`
2. Activez le plugin depuis l'administration WordPress
3. Configurez vos clés API Paigami dans WooCommerce > Paramètres > Paiements

## ⚙️ Configuration

### Clés API
Récupérez vos clés depuis votre dashboard Paigami:
- **API Key**: Clé publique pour l'authentification
- **Secret Key**: Clé secrète pour la validation des webhooks
- **Webhook URL**: `https://votresite.com/wc-api/paigami_webhook`

### Mode Test
Activez le mode test pour utiliser l'environnement de développement Paigami sans affecter les transactions réelles.

## 🚀 Flux de Paiement

1. **Sélection du pays** - Le client choisit son pays
2. **Sélection de l'opérateur** - Les wallets disponibles s'affichent
3. **Conversion du montant** - Affichage du montant converti si nécessaire
4. **Saisie du téléphone** - Numéro avec indicatif pays
5. **OTP si requis** - Code à 6 chiffres pour certains opérateurs
6. **Redirection** - Vers le paiement de l'agrégeur
7. **Confirmation** - Webhook met à jour le statut de la commande

## 🛠️ Structure du Plugin

```
paigami-woocommerce-plugin/
├── paigami-woocommerce.php          # Fichier principal
├── includes/
│   ├── class-paigami-api.php        # Intégration API Paigami
│   ├── class-paigami-gateway.php    # Passerelle WooCommerce
│   └── class-paigami-webhook.php    # Gestion webhooks
├── assets/
│   ├── js/
│   │   └── paigami-checkout.js      # Interface checkout
│   └── css/
│       ├── paigami-checkout.css     # Styles checkout
│       └── paigami-admin.css        # Styles administration
└── languages/                       # Fichiers de traduction
```

## 📋 Compatibilité

- **WordPress**: 5.8+
- **WooCommerce**: 7.0+
- **PHP**: 7.4+
- **Navigateurs**: Modernes (Chrome, Firefox, Safari, Edge)

## 🔧 Développement

### Webhooks Disponibles
- `payment.success` - Paiement réussi
- `payment.failed` - Paiement échoué
- `payment.pending` - Paiement en attente
- `payment.processing` - Paiement en traitement
- `payment.cancelled` - Paiement annulé

### API Endpoints Utilisés
- `GET /metadata/countries` - Liste des pays
- `GET /metadata/wallets?country_id=X` - Wallets par pays
- `GET /checkout/options` - Options et frais
- `POST /payments/init` - Initialiser paiement
- `GET /payments/{reference}` - Statut paiement

### Logging
Activez le mode debug pour voir les requêtes API dans WooCommerce > État > Logs.

## 🌐 Devise Supportées

- **XOF** - Franc CFA BCEAO
- **NGN** - Naira Nigérian  
- **GHS** - Cedi Ghanéen
- **KES** - Shilling Kenyan
- **UGX** - Shilling Ougandais
- **RWF** - Franc Rwandais
- **TZS** - Shilling Tanzanien
- **ZMW** - Kwacha Zambien
- **MWK** - Kwacha Malawien
- **BIF** - Franc Burundais

## 🔒 Sécurité

- Validation côté serveur des données
- Signatures HMAC pour les webhooks
- Nonces WordPress pour les requêtes AJAX
- Sanitisation des entrées utilisateur
- Rate limiting intégré via l'API Paigami

## 📞 Support

Pour le support technique:
- Documentation: https://docs.paigami.com
- Email: support@paigami.com
- Issues GitHub: Reportez les bugs via GitHub

## 📝 Licence

GPL v2 ou ultérieure

---

**Développé avec ❤️ par Paigami** - Le paiement mobile simple et sécurisé pour l'Afrique