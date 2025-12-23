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
	
# Documentazione Tecnica Master: Integrazione WooCommerce > SAP

**Oggetto:** Workflow dettagliato per la sincronizzazione, filtraggio e fatturazione ordini.
**File Core:** `paypal_cron.php`, `setInvoiceCurl.php`, `UserController.php`, `DBMoodle.php`

---

## 1. Panoramica Architetturale

Il sistema non collega direttamente WooCommerce a SAP in tempo reale. Utilizza un'architettura asincrona a **due stadi** con una tabella di staging intermedia (`moodle_payments`) per disaccoppiare la fase di vendita dalla fase contabile.

### Attori del Sistema
1.  **WooCommerce (Source):** Fonte della verità per dati anagrafici e transazionali.
2.  **Staging DB (Buffer):** Tabella locale `moodle_payments` che funge da coda di lavorazione FIFO (First-In, First-Out).
3.  **Cron Import (Loader):** Script che alimenta la coda (`paypal_cron.php`).
4.  **Cron Trigger (Dispatcher):** Script che svuota la coda (`setInvoiceCurl.php`).
5.  **API Controller (Processor):** Logica di business che dialoga con SAP (`UserController.php`).

---

## 2. Dettaglio Flussi Logici e Controlli

### FASE 1: Importazione (WooCommerce > Staging)
**File Responsabile:** `paypal_cron.php` (supportato da `woocommerce_helpers.php`)
**Frequenza:** Periodica (es. ogni 30-60 min).

In questa fase, il sistema scarica gli ordini. Per garantire l'integrità dei dati, vengono applicati **3 livelli di filtraggio sequenziale**. Un ordine deve superare tutti e tre i livelli per entrare in coda.

#### Livello 1: Filtro di Stato (WooCommerce Query)
Il sistema interroga le API/DB di WooCommerce richiedendo un sottoinsieme specifico di ordini.
* **Logica:** `WHERE status IN ('wc-processing', 'wc-completed')`
* **Obiettivo:** Vengono ignorati alla fonte:
    * Ordini cancellati (`cancelled`).
    * Ordini falliti (`failed`).
    * Ordini in attesa di pagamento (`pending`).
    * Rimborsi (`refunded`).

#### Livello 2: Controllo Anti-Duplicazione (Local Check)
Prima di effettuare l'inserimento (`INSERT`), il sistema verifica se l'ordine è già stato preso in carico in passato.
* **Query di Verifica:**
    ```sql
    SELECT count(*) FROM moodle_payments WHERE payment_id = [ID_ORDINE_WOO]
    ```
* **Logica Decisionale:**
    * **SE Count > 0:** L'ordine esiste già.
        * **Azione:** **SKIP** (Passa all'ordine successivo).
    * **SE Count == 0:** L'ordine è nuovo.
        * **Azione:** Procedi al Livello 3.
* **Obiettivo:** Idempotenza. Garantisce che un ordine venga processato una sola volta, indipendentemente da quante volte gira il CRON.

#### Livello 3: Finestra Temporale Fiscale (Solo Bonifici)
Se il metodo di pagamento è Bonifico Bancario (`bacs`), viene applicata una regola di conformità fiscale per la sequenzialità delle fatture.
* **Dato Analizzato:** Metadato `bacs_date` (Data Valuta) dell'ordine.
* **Regola del "Vigile":**
    * Si calcola la data limite: **1° giorno del mese precedente** rispetto alla data odierna.
    * **Condizione:** `IF (Data_Bonifico < Data_Limite)`
* **Logica Decisionale:**
    * **SE Vecchio (es. Ottobre lavorato a Dicembre):**
        * **Azione:** **SKIP** (Loggato come "Fuori finestra temporale").
    * **SE Valido (Dicembre o Novembre):**
        * **Azione:** **INSERT** in `moodle_payments`.
* **Obiettivo:** Prevenire l'emissione di fatture con data pagamento troppo antecedente, che violerebbe la sequenza numerica/temporale richiesta dall'Agenzia delle Entrate.

---

### FASE 2: Il Trigger (Selezione Lavorazioni)
**File Responsabile:** `setInvoiceCurl.php`
**Frequenza:** Cron frequente (es. 5-10 min).

Lo script seleziona dalla tabella di staging i candidati per la fatturazione effettiva.

#### La Query di Selezione "Intelligente"
```sql
SELECT * FROM moodle_payments
WHERE sales = '0'
AND `logfile` IS NULL
ORDER BY id ASC
LIMIT 10;