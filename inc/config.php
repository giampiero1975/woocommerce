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
            'url'    => 'https://professionefarmacia.it',
            'key'    => 'ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', // Inserisci qui la tua Consumer Key
            'secret' => 'cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'  // Inserisci qui la tua Consumer Secret
        ]
    ];
    
    // Se in futuro ti servissero altre costanti comuni (es. per SAP o Log)
    // puoi aggiungerle qui sotto.
}