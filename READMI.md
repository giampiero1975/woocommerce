# Progetto WooCommerce

Progetto per generare fatture ed incassi attraverso "moodlesap", creato per implementare il flusso odierno che gestisce i pagamenti su Moodle classici.
Questa applicazione si occupa di recuperare i pagamenti ricevuti dalla piattaforma WP e WooCommerce, silmulando il flusso tradizionale nel DB e tabelle gia utilizzate da moodlesap.

## Prerequisiti

* PHP versione X.Y (es. PHP 8.1)
* Composer installato globalmente (vedi [getcomposer.org](https://getcomposer.org/))
* Node.js e npm/yarn (se usi strumenti frontend che li richiedono)
* Un server web locale (es. Laragon, XAMPP, Docker)
* Database (es. MySQL, MariaDB)

## Installazione e Setup Locale

1.  **Clona il repository:**
    ```bash
    git clone [http://git.metmi.lan/MarketingTelematica/woocommerce.git](http://git.metmi.lan/MarketingTelematica/woocommerce.git)
    cd woocommerce
    ```

2.  **Installa le dipendenze PHP con Composer:**
    ```bash
    composer install
    ```

3.  **Installa le dipendenze frontend (se applicabile):**
    ```bash
    # npm install
    # npm run dev 
    # o comandi yarn
    ```