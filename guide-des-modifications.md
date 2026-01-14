# Guide de correction des bugs - Plugin Paigami WooCommerce

## Problèmes identifiés

### 1. Erreur principale : `wcBlocksCheckout is undefined`

**Cause** : Le fichier `paigami-blocks.js` tente d'accéder à des propriétés de `wcBlocksCheckout` qui n'existent pas ou ne sont pas chargées.

### 2. Problèmes de dépendances

- Mauvaise vérification de la disponibilité des dépendances
- Pas de fallback en cas d'indisponibilité
- Ordre de chargement des scripts incorrect

### 3. Problèmes d'enregistrement des données

- Les données de configuration ne sont pas correctement passées au frontend
- Manque de fonction `registerPaymentMethodData` utilisée par WooCommerce Blocks

## Solutions appliquées

### ✅ 1. Correction de `paigami-blocks.js`

**Changements principaux** :

- Vérification robuste de toutes les dépendances avant utilisation
- Utilisation directe de `window.wc.wcSettings.getSetting()` au lieu de destructuring
- Fallback pour les composants non disponibles
- Simplification de la structure des composants

**Code corrigé** :

```javascript
// Vérification sécurisée des dépendances
if ( typeof window.wc === 'undefined' || 
     typeof window.wc.wcBlocksRegistry === 'undefined' ||
     typeof window.wc.wcSettings === 'undefined' ) {
    console.warn( 'Paigami: WooCommerce Blocks dependencies not available' );
    return;
}

// Récupération des données avec getSetting
const settings = window.wc.wcSettings.getSetting( 'paigami_data', {} );
```

### ✅ 2. Mise à jour de `class-paigami-blocks-support.php`

**Améliorations** :

- Ajout de `enqueue_block_data()` pour enregistrer les données avec `wcSettings`
- Utilisation de `wp_add_inline_script()` pour injecter la configuration
- Enregistrement via `registerPaymentMethodData()` pour WooCommerce Blocks

**Code clé** :

```php
wp_add_inline_script(
    'wc-blocks-registry',
    sprintf(
        "window.wc.wcSettings.registerPaymentMethodData('paigami', %s);",
        wp_json_encode($script_data)
    ),
    'before'
);
```

### ✅ 3. Correction de `class-paigami-block-payment-method.php`

**Changements** :

- Gestion correcte des dépendances du script
- Création d'un fichier asset.php automatique
- Meilleure gestion du cache des pays

### ✅ 4. Amélioration de `class-paigami-gateway.php`

**Ajouts** :

- Méthode `init_blocks_support()` pour initialiser les Blocks au bon moment
- Méthode `get_post_data()` pour gérer les données des Blocks et du checkout classique
- Support des métadonnées de paiement pour les deux modes

## Instructions d'installation

### Étape 1 : Remplacer les fichiers

Remplacez les fichiers suivants dans votre plugin :

1. **assets/js/paigami-blocks.js**
2. **includes/class-paigami-blocks-support.php**
3. **includes/class-paigami-block-payment-method.php**
4. **includes/class-paigami-gateway.php** (seulement les méthodes modifiées)

### Étape 2 : Vider le cache

```bash
# Vider le cache WordPress
wp cache flush

# Vider le cache des transients
wp transient delete --all

# Régénérer les assets (si vous utilisez un bundler)
npm run build
```

### Étape 3 : Tester

1. **Activer le mode debug** :

   ```php
   define('WP_DEBUG', true);
   define('SCRIPT_DEBUG', true);
   ```

2. **Tester le checkout classique** :
   - Ajouter un produit au panier
   - Aller sur la page de paiement classique
   - Sélectionner Paigami
   - Vérifier que tous les champs s'affichent

3. **Tester WooCommerce Blocks** :
   - Installer WooCommerce Blocks
   - Utiliser le bloc Checkout
   - Sélectionner Paigami
   - Vérifier le fonctionnement

## Fichier asset.php (optionnel)

Si vous voulez optimiser davantage, créez un fichier `assets/js/paigami-blocks.asset.php` :

```php
<?php
return array(
    'dependencies' => array(
        'wc-blocks-registry',
        'wc-settings',
        'wp-element',
        'wp-i18n',
        'wp-polyfill'
    ),
    'version' => '1.0.0'
);
```

## Vérifications post-installation

### Console du navigateur

```javascript
// Vérifier que WooCommerce Blocks est chargé
console.log(window.wc);

// Vérifier que Paigami est enregistré
console.log(window.wc.wcSettings.getSetting('paigami_data'));

// Vérifier l'enregistrement de la méthode
console.log(window.wc.wcBlocksRegistry);
```

### Logs WordPress

```php
// Dans wp-content/debug.log
[date] Paigami: Payment method registered for blocks
[date] Paigami: Countries loaded: 12
```

## Dépannage

### Problème : "Dependencies not available"

**Solution** : Assurez-vous que WooCommerce Blocks est installé et activé (version 8.0+)

### Problème : "Cannot read properties of undefined"

**Solution** :

1. Vider le cache du navigateur (Ctrl+Shift+Delete)
2. Désactiver puis réactiver le plugin
3. Vérifier que jQuery et wp-element sont chargés

### Problème : Les pays ne s'affichent pas

**Solution** :

```php
// Vider le cache manuellement
delete_transient('paigami_countries_blocks');
wp_cache_delete('paigami_countries');
```

### Problème : Erreurs AJAX

**Solution** :

1. Vérifier que le nonce est valide
2. Vérifier les permissions AJAX (`wp_ajax_*` et `wp_ajax_nopriv_*`)
3. Activer le mode debug pour voir les erreurs

## Points de test

- [ ] Checkout classique fonctionne
- [ ] Checkout Blocks fonctionne  
- [ ] Sélection de pays charge les wallets
- [ ] Sélection de wallet affiche le champ téléphone
- [ ] OTP s'affiche si requis
- [ ] Validation des champs fonctionne
- [ ] Paiement s'initialise correctement
- [ ] Redirection vers l'agrégateur fonctionne
- [ ] Webhooks mettent à jour la commande
- [ ] Pas d'erreurs JavaScript dans la console
- [ ] Mode test fonctionne

## Support technique

Si les problèmes persistent :

1. **Collecter les informations** :
   - Version WordPress
   - Version WooCommerce  
   - Version PHP
   - Thème actif
   - Plugins actifs
   - Console JavaScript (F12)
   - Logs WordPress

2. **Vérifier la compatibilité** :
   - WooCommerce : 7.0+
   - WooCommerce Blocks : 8.0+
   - PHP : 7.4+
   - WordPress : 5.8+

3. **Contacter le support** :
   - Email : <support@paigami.com>
   - Documentation : <https://docs.paigami.com>
   - GitHub Issues : (si disponible)

## Prochaines étapes

1. Tester en environnement de staging
2. Effectuer des tests de paiement complets
3. Vérifier les webhooks en production
4. Monitorer les logs pendant 24h
5. Déployer en production

---

**Version du guide** : 1.0.0  
**Date** : 2026-01-14  
**Auteur** : Support Paigami
