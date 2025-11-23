# Workout Tracker PHP

Un'applicazione semplice per registrare e consultare i propri workout utilizzando:

- **PHP 7.3**
- **HTML + jQuery + Bootstrap**
- **File CSV** come archivio dati (nessun database)

## Cosa fa

- Permette il **login** con credenziali configurabili.
- Consente di **registrare gli allenamenti giornalieri**.
- Ogni workout contiene:
  - elenco degli esercizi
  - per ogni esercizio: serie in formato **reps + peso**
- I workout precedenti possono essere:
  - **visualizzati**
  - **clonati** come base per l’allenamento odierno
- L’interfaccia è progettata per funzionare **bene da cellulare**.
- Tutti i dati (log, routine, utenti) sono memorizzati in file CSV che il PHP gestisce tramite semplici API.

## Per cosa è utile

- Tracciare l’allenamento personale senza database.
- Avere una soluzione portabile su qualsiasi hosting con PHP.
- Usare routine predefinite e tenere traccia dei progressi giorno per giorno.
