<?php
/**
 * inc/config.php - Versione Ad Hoc per il modulo Check Ordini
 */

class costanti {
    
    // --- CONFIGURAZIONE ISTANZE WOOCOMMERCE ---
    // Questi dati servono a WooCommerceModel per connettersi via API
    const WOOCOMMERCE_INSTANCES = [
        'mdl_formazioneoss' => [
            'url'    => 'https://formazioneoss.it',
            'key'    => 'ck_f560fc81cfc117a7e46f8c469def834b9dda3b5a',
            'secret' => 'cs_f3f0bebf009322f74965aaf24899f0ad0b924f60'
        ],
        'mdl_professionefarmacia' => [
            'url' => 'https://professionefarmacia.it',
            'key' => 'ck_7ea7443ddd23a5f42cfb73576cb08ebbb67d486d',
            'secret' => 'cs_83861ba46e0a1665d59587f17dea41031e458ea1'
        ],
        'mdl_professioneoculista' => [
            // WooCommerce -> Impostazioni -> Avanzate -> REST API
            'url' => 'https://professioneoculista.it',
            'key' => 'ck_03fc9f3a3c6dc394e2925bd9356411cf3933b13b',
            'secret' => 'cs_0b33a635a8beee65d86692aa477425f7470f496f',
            'idnumber_sap' => 'MEcdPO' // --> verifica con Alessia
        ],
    ];
}