
                <!-- Print-only: Cover Page -->
                <div class="print-cover">
                    <img class="cover-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
                    <div class="cover-rule"></div>
                    <h1>Documentazione della Piattaforma</h1>
                    <div class="cover-edition">Governance, Risk &amp; Compliance &bull; Third Party Risk Management</div>
                    <div class="cover-version">Versione 2.6.2</div>
                    <div class="cover-rule-bottom"></div>
                    <div class="cover-meta">
                        <strong>Data:</strong> <?php echo date('F j, Y'); ?><br>
                        <strong>Classificazione:</strong> Solo per uso interno<br>
                        <strong>Preparato da:</strong> Team di Amministrazione GRC
                    </div>
                </div>

                <!-- Print-only: Introduction & Purpose -->
                <div class="print-intro">
                    <h2>Introduzione</h2>

                    <h3>Scopo</h3>
                    <p>Questo documento fornisce una documentazione completa per la Piattaforma Fair TPRM &amp; GRC. Funge sia da guida utente che da manuale di riferimento per tutto il personale coinvolto nella gestione del rischio di terze parti, nella governance, nella valutazione del rischio e nelle operazioni di conformità.</p>
                    <p>Il pubblico previsto include analisti GRC, responsabili della conformità, revisori, personale di sicurezza IT, team di approvvigionamento e amministratori di sistema. Che tu stia conducendo la tua prima valutazione di conformità o gestendo un programma di audit in corso, questa guida fornisce le istruzioni passo-passo di cui hai bisogno.</p>

                    <h3>Ambito</h3>
                    <p>Questa documentazione copre i seguenti moduli e funzionalità della piattaforma:</p>
                    <ul>
                        <li><strong>Modulo GRC</strong> &mdash; Valutazioni di conformità unificate su più framework (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, NIST 800-171), gestione dei controlli interni, raccolta di prove, gestione del ciclo di vita delle policy, gestione degli audit, registro dei rischi, monitoraggio continuo e punteggio di maturità</li>
                        <li><strong>Modulo TPRM</strong> &mdash; Onboarding di fornitori terzi, classificazione del rischio, valutazioni della sicurezza, analisi quantitativa del rischio (FAIR), punteggi di sicurezza esterni, monitoraggio del rischio di quarte parti e scoperta di shadow SaaS</li>
                        <li><strong>Portale Admin</strong> &mdash; Configurazione del sistema, gestione di utenti e gruppi, branding, impostazioni email, integrazione SSO/SAML e configurazione della piattaforma AI</li>
                    </ul>

                    <h3>Come Utilizzare Questa Guida</h3>
                    <p>Questa guida è organizzata in quattro parti. La <strong>Parte 1 (Per Iniziare)</strong> copre la navigazione della piattaforma, i ruoli utente e il primo accesso. La <strong>Parte 2 (Modulo GRC)</strong> fornisce una guida dettagliata del processo di valutazione della conformità, a partire dalla creazione della prima valutazione fino alla raccolta di prove, al punteggio e alla generazione dei report. La <strong>Parte 3 (Modulo TPRM)</strong> copre la gestione del rischio dei fornitori. La <strong>Parte 4 (Portale Admin)</strong> copre l'amministrazione del sistema.</p>
                    <p>Se sei nuovo sulla piattaforma, inizia con la sezione <em>Per Iniziare</em> e poi segui la Guida Rapida GRC in cinque passi. Ogni passo include istruzioni precise, clic dopo clic.</p>

                    <h3>Convenzioni del Documento</h3>
                    <p>In tutto questo documento vengono utilizzate le seguenti convenzioni:</p>
                    <ul>
                        <li><strong>Il testo in grassetto</strong> indica concetti importanti o enfasi</li>
                        <li><code>La formattazione del codice</code> indica valori che digiti o riferimenti generati dal sistema</li>
                        <li>Gli elenchi numerati indicano procedure sequenziali da seguire nell'ordine indicato</li>
                        <li>Le caselle di nota forniscono suggerimenti, avvertenze e contesto importante</li>
                    </ul>
                </div>

                <!-- Print-only: Table of Contents (populated by JS) -->
                <div class="print-toc">
                    <h2>Indice dei Contenuti</h2>
                    <div id="tocBody"></div>
                </div>

                <!-- Main content body -->
                <div class="doc-body">

                <h1>Documentazione della Piattaforma</h1>
                <p class="doc-subtitle">Fair TPRM &amp; GRC Platform &mdash; Version 2.6.2 &mdash; Ultimo aggiornamento: <?php echo date('F j, Y'); ?></p>

                <div style="margin-bottom:20px;">
                    <button class="btn-doc" id="printBtn">Scarica PDF</button>
                </div>

<!-- old doc-toc removed — print-toc above now serves as the on-screen TOC -->


<!-- ================================================================
     GETTING STARTED
     ================================================================ -->
<div class="doc-section" id="overview">
    <h2>Panoramica della Piattaforma</h2>
    <p>Questa piattaforma fornisce due moduli integrati per la gestione della postura di sicurezza della tua organizzazione:</p>
    <ul>
        <li><strong>TPRM (Third Party Risk Management)</strong> &mdash; Monitora, valuta e assegna un punteggio ai tuoi fornitori e prestatori di servizi. Comprendi il rischio di sicurezza che ogni fornitore rappresenta per la tua organizzazione.</li>
        <li><strong>GRC (Governance, Risk &amp; Compliance)</strong> &mdash; Gestisci i framework di conformità (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls e altri), rispondi a un unico questionario unificato che copre tutti i framework contemporaneamente, monitora i controlli interni, carica prove, gestisci policy ed esegui audit.</li>
    </ul>
    <p>Gli amministratori hanno anche accesso al <strong>Portale Admin</strong> per la configurazione del sistema, la gestione degli utenti, le integrazioni e la manutenzione.</p>

    <div class="callout callout-success">
        <strong>Concetto Chiave &mdash; Una Valutazione, Molti Framework:</strong> Il modulo GRC utilizza un <em>questionario di valutazione unificato</em> con 146 domande su 14 domini di sicurezza. Quando rispondi a queste domande una sola volta, la piattaforma calcola automaticamente la percentuale di conformità rispetto a ogni framework supportato (SOC 2, ISO 27001, PCI DSS, ecc.) &mdash; nessun lavoro duplicato richiesto.
    </div>
</div>

<div class="doc-section" id="navigation">
    <h2>Navigazione nella Barra Laterale</h2>
    <p>La barra laterale sinistra è il tuo strumento di navigazione principale. È organizzata in moduli e sezioni espandibili:</p>
    <ol class="steps">
        <li>In cima alla barra laterale vedi il logo aziendale e il testo del branding.</li>
        <li>Sotto di esso ci sono due intestazioni di modulo espandibili: <span class="menu-label">Modulo TPRM</span> e <span class="menu-label">Modulo GRC</span>. Fai clic su un'intestazione per espanderla o comprimerla. Il browser ricorda quali moduli sono aperti.</li>
        <li>All'interno di ciascun modulo ci sono <strong>sezioni</strong> espandibili (es. "Conformità", "Prove e Monitoraggio", "Valutazione e Audit"). Fai clic sul titolo di una sezione per espanderla e visualizzare i collegamenti di navigazione al suo interno.</li>
        <li>In fondo alla barra laterale troverai i collegamenti di utilità: <span class="menu-label">Dashboard</span>, <span class="menu-label">Profilo</span>, <span class="menu-label">Documentazione</span> (questa pagina) e <span class="menu-label">Amministrazione</span> (solo admin).</li>
    </ol>

    <h3>Struttura della Barra Laterale del Modulo GRC</h3>
    <p>Quando espandi <span class="menu-label">Modulo GRC</span>, vedrai queste sezioni:</p>
    <table class="doc-table">
        <tr><th>Sezione</th><th>Pagine al Suo Interno</th><th>Cosa Contiene</th></tr>
        <tr><td><strong>Conformità</strong></td><td>Dashboard GRC, Framework, Controlli Interni, Crosswalk dei Framework</td><td>Panoramica della postura di conformità, gestione dei framework, libreria dei controlli e mappatura tra framework</td></tr>
        <tr><td><strong>Prove &amp; Monitoraggio</strong></td><td>Libreria Prove, Monitor Continui</td><td>Carica e gestisci le prove di conformità; configura controlli di conformità automatizzati</td></tr>
        <tr><td><strong>Gestione Policy</strong></td><td>Policy</td><td>Crea, versiona, approva e pubblica le policy organizzative</td></tr>
        <tr><td><strong>Valutazione &amp; Audit</strong></td><td>Punteggio Maturità CSF, Questionario di Valutazione, Casella Attività, Audit, Riscontri, Registro dei Rischi</td><td>Il questionario di valutazione unificato, dashboard di maturità, audit e monitoraggio dei rischi</td></tr>
    </table>
</div>

<div class="doc-section" id="roles">
    <h2>Ruoli Utente &amp; Permessi</h2>
    <p>Gli utenti sono assegnati a uno o più <strong>Gruppi ACL</strong> che determinano cosa possono vedere e fare. Un amministratore assegna i gruppi tramite <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Utenti</span> &rarr; pulsante <span class="btn-label">Gruppi</span>.</p>
    <table class="doc-table">
        <tr><th>Gruppo</th><th>Cosa Puoi Fare</th></tr>
        <tr><td><strong>Administrator</strong></td><td>Accesso completo a tutto &mdash; tutti i moduli, impostazioni admin, gestione utenti e configurazione del sistema</td></tr>
        <tr><td><strong>Cyber TPRM</strong></td><td>Accesso completo al modulo TPRM &mdash; creare/modificare/eliminare fornitori, eseguire valutazioni, analisi FAIR, punteggi</td></tr>
        <tr><td><strong>Cyber GRC</strong></td><td>Accesso completo al modulo GRC &mdash; gestire framework, eseguire valutazioni, caricare prove, gestire policy, eseguire audit, gestire rischi</td></tr>
        <tr><td><strong>GRC Contributors</strong></td><td>Accesso GRC limitato &mdash; completare le attività assegnate, fornire prove, rispondere alle domande di valutazione assegnate</td></tr>
        <tr><td><strong>Auditor</strong></td><td><strong>Accesso in sola lettura</strong> a entrambi i moduli TPRM e GRC &mdash; può visualizzare tutto, scaricare prove e generare report, ma non può creare, modificare o eliminare</td></tr>
        <tr><td><strong>Procurement</strong></td><td>Creare e gestire le richieste di onboarding dei fornitori, caricare documenti dei fornitori</td></tr>
        <tr><td><strong>Stakeholder</strong></td><td>Visualizzare le proprie richieste di fornitori e rispondere alle attività assegnate</td></tr>
    </table>

    <div class="callout callout-info">
        <strong>Per vedere il modulo GRC nella barra laterale:</strong> Devi essere nel gruppo <strong>Administrator</strong>, <strong>Cyber GRC</strong> o <strong>Auditor</strong>. Se non vedi il Modulo GRC nella barra laterale, chiedi al tuo amministratore di aggiungerti a uno di questi gruppi.
    </div>
</div>

<div class="doc-section" id="first-login">
    <h2>Il Tuo Primo Accesso</h2>
    <ol class="steps">
        <li>Apri il tuo browser web e naviga all'URL della piattaforma (es. <code>https://tprm.yourcompany.com</code>).</li>
        <li>Inserisci il tuo <span class="field-label">Nome utente</span> e la tua <span class="field-label">Password</span> forniti dall'amministratore.</li>
        <li>Se l'autenticazione a due fattori (TOTP) è abilitata per il tuo account, apri la tua app di autenticazione (Google Authenticator, Microsoft Authenticator, ecc.) e inserisci il codice a 6 cifre quando richiesto.</li>
        <li>Atterrerai sulla <strong>Dashboard</strong>. La barra superiore mostra "Benvenuto, [Il tuo nome]" con i collegamenti ad Admin (se sei un amministratore), Profilo e Logout.</li>
        <li>Guarda la barra laterale sinistra. Se sei nel gruppo <strong>Cyber GRC</strong> o <strong>Administrator</strong>, vedrai <span class="menu-label">Modulo GRC</span> nella barra laterale. Fai clic per espandere la navigazione GRC.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/dashboard.png" alt="The platform dashboard after logging in" loading="lazy">
        <figcaption><strong>La Dashboard.</strong> Dopo aver effettuato l'accesso arrivi qui. La barra superiore (in alto a destra) contiene <strong>Admin</strong>, <strong>Profilo</strong> e <strong>Logout</strong>. La barra laterale sinistra è il menu principale.</figcaption>
    </figure>
</div>


<!-- ================================================================
     WHAT'S NEW IN 2.6.2
     ================================================================ -->
<div class="doc-section" id="whats-new">
    <h2>Novità della Versione 2.6.2</h2>
    <p>La versione 2.6.2 aggiunge diverse funzionalità incentrate su <strong>onboarding dei fornitori, collaborazione negli acquisti, supporto multilingue e scoperta di shadow-SaaS</strong>. Se hai utilizzato una versione precedente, ecco le novità. Ogni voce rimanda alla relativa guida completa più avanti in questa guida.</p>
    <table class="doc-table">
        <tr><th>Nuova Funzionalità</th><th>Cosa Fa</th><th>Per Chi È</th></tr>
        <tr><td><strong><a href="#language">Impostazioni lingua</a></strong></td><td>Usa la piattaforma in 8 lingue. Ogni persona sceglie la propria lingua; gli admin scelgono quali lingue sono disponibili.</td><td>Tutti</td></tr>
        <tr><td><strong><a href="#onboarding-workflow">Onboarding Procurement &amp; ID Fornitore</a></strong></td><td>Un fornitore deve essere inserito tramite procurement e avere un ID Fornitore (VID) valido prima di poter essere sottoposto a revisione cyber.</td><td>Procurement, Stakeholder</td></tr>
        <tr><td><strong><a href="#ai-review">Revisione AI per i fornitori</a></strong></td><td>Uno stato di revisione dedicato per i fornitori i cui servizi utilizzano AI, oltre a un'azione "Forza Revisione AI".</td><td>Cyber TPRM, Admin</td></tr>
        <tr><td><strong><a href="#procurement-cyber-status">Stato Cyber Procurement</a></strong></td><td>Una pagina live che mostra i fornitori in revisione, con uno storico degli aggiornamenti che il team cyber condivide con il procurement, più un digest email settimanale.</td><td>Procurement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#shadow-saas-grip">Integrazione Grip Shadow SaaS</a></strong></td><td>Scopri automaticamente le app SaaS utilizzate in tutta l'organizzazione e aggiungile all'elenco Shadow SaaS.</td><td>Admin</td></tr>
        <tr><td><strong><a href="#shadow-saas-hero">Integrazione Hero Shadow SaaS</a></strong></td><td>Un provider Shadow SaaS alternativo: scopri fornitori e problemi di sicurezza da HERO Security e inseriscili nello stesso elenco Shadow SaaS. Grip e Hero si escludono a vicenda &mdash; usa uno o l'altro.</td><td>Admin</td></tr>
        <tr><td><strong><a href="#zscaler">Blocco Zscaler</a></strong></td><td>Blocca il dominio web di un'app non autorizzata direttamente in Zscaler con un solo clic.</td><td>Admin</td></tr>
        <tr><td><strong><a href="#admin-updates">Aggiornamenti in-app</a></strong></td><td>Controlla il tuo registry per una versione più recente e aggiorna dall'interno del Portale Admin.</td><td>Admin</td></tr>
        <tr><td><strong><a href="#question-types">Tipi di domanda Telefono &amp; VAT</a></strong></td><td>Nuovi tipi di campo per valutazione/onboarding: un numero di telefono con selettore prefisso internazionale &amp; bandiera (formattato automaticamente), e un numero di partita IVA UE con doppia immissione e validazione live gratuita rispetto al servizio ufficiale EU VIES.</td><td>Tutti</td></tr>
        <tr><td><strong><a href="#question-types">Miglioramenti ai dati fornitore &amp; ricerca</a></strong></td><td>Archivia un numero di partita IVA per ogni fornitore (mostrato nella pagina fornitore con un collegamento rapido "Aggiungi VAT"), trova i fornitori per numero di partita IVA nella ricerca rapida, e un banner di punteggio Procurement-Onboarding più chiaro.</td><td>Procurement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#admin-backup">Backup di database di grandi dimensioni</a></strong></td><td>Il backup e il ripristino supportano ora database di diversi gigabyte e record molto grandi senza timeout.</td><td>Admin</td></tr>
        <tr><td><strong><a href="#assessment-forms">Moduli di valutazione &amp; Compilazione automatica AI</a></strong></td><td>Scarica una valutazione come modulo PDF compilabile o cartella di lavoro Excel, reimporta un file completato e &mdash; con un provider AI &mdash; compila automaticamente le risposte dai certificati correnti del fornitore.</td><td>Cyber TPRM, Admin</td></tr>
        <tr><td><strong><a href="#tprm-action-plan">Piano d'Azione Fornitore</a></strong></td><td>Pianifica azioni di follow-up per un fornitore (contatto, invio valutazione, forzatura revisione annuale) con date di scadenza, responsabili, avvisi email e note di stato.</td><td>Cyber TPRM, Admin</td></tr>
        <tr><td><strong><a href="#custom-onboarding">Campi di onboarding personalizzati &amp; Dati Personalizzati</a></strong></td><td>Acquisisci campi extra, specifici dell'organizzazione, su un fornitore con visibilità per ruolo, modificali nella scheda Dati Personalizzati e leggili nell'esportazione CSV e nell'API.</td><td>Admin, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#breach-alerts">Avvisi di Violazione / Cyber</a></strong></td><td>Un feed di violazioni della catena di approvvigionamento (inclusi gli incidenti Grip) con un drill-down degli utenti interessati e conferma / falso positivo / eliminazione in blocco.</td><td>Cyber TPRM, Admin</td></tr>
        <tr><td><strong><a href="#admin-acl-groups">Gruppi di controllo accessi personalizzati</a></strong></td><td>Crea i tuoi gruppi ACL, clona i permessi da un gruppo esistente e imposta Lettura o Lettura/Scrittura per modulo. I gruppi predefiniti sono protetti.</td><td>Admin</td></tr>
        <tr><td><strong><a href="#admin-templates">Generatore di Template di Valutazione</a></strong></td><td>Nuovi tipi di domanda (selezione multipla, telefono, VAT), istruzioni sui certificati definite dal template, gating dei campi per ruolo e template disattivati nascosti per impostazione predefinita.</td><td>Admin, Cyber TPRM</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>Come faccio a sapere quale versione sto usando?</strong> Gli amministratori possono andare su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Versione</span> per vedere la versione installata. Questa guida descrive la <strong>v2.6.2</strong>. Vedi <a href="#admin-updates">Aggiornamento della Piattaforma</a>.
    </div>
</div>

<div class="doc-section" id="language">
    <h2>Cambiare la Lingua <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>L'interfaccia della piattaforma può essere visualizzata in <strong>8 lingue</strong>. Ogni persona sceglie la propria lingua &mdash; cambiarla influenza solo il <em>tuo</em> schermo, non quello degli altri. La tua scelta viene ricordata ogni volta che accedi.</p>

    <h3>Lingue disponibili</h3>
    <table class="doc-table">
        <tr><th>Lingua</th><th>Mostrata nel menu come</th></tr>
        <tr><td>Inglese</td><td>English</td></tr>
        <tr><td>Spagnolo</td><td>Espa&ntilde;ol</td></tr>
        <tr><td>Italiano</td><td>Italiano</td></tr>
        <tr><td>Ucraino</td><td>&#1059;&#1082;&#1088;&#1072;&#1111;&#1085;&#1089;&#1100;&#1082;&#1072;</td></tr>
        <tr><td>Cinese (Semplificato)</td><td>&#20013;&#25991;&#65288;&#31616;&#20307;&#65289;</td></tr>
        <tr><td>Hindi</td><td>&#2361;&#2367;&#2344;&#2381;&#2342;&#2368;</td></tr>
        <tr><td>Francese</td><td>Fran&ccedil;ais</td></tr>
        <tr><td>Portoghese</td><td>Portugu&ecirc;s</td></tr>
    </table>
    <p class="callout callout-info" style="margin-top:0;"><strong>Nell'elenco appariranno solo le lingue che il tuo amministratore ha attivato.</strong> L'inglese è sempre disponibile e non può essere disattivato.</p>

    <h3>Come cambiare la lingua (passo dopo passo)</h3>
    <ol class="steps">
        <li>Fai clic su <span class="menu-label">Profilo</span> nell'angolo in alto a destra di qualsiasi pagina.</li>
        <li>Nella pagina Profilo, scorri verso il basso fino alla scheda <span class="field-label">Preferenza Lingua</span>.</li>
        <li>Fai clic sul menu a discesa <span class="field-label">Lingua</span> e scegli la tua lingua. Per tornare alla lingua impostata dall'amministratore per tutti, scegli <strong>Impostazione predefinita di sistema</strong>.</li>
        <li>Fai clic su <span class="btn-label">Aggiorna Lingua</span>. La pagina si ricarica e i menu, i pulsanti e le etichette appaiono ora nella lingua scelta.</li>
    </ol>
    <figure class="doc-figure narrow">
        <img src="app/docs/profile-language-card.png" alt="Language Preference card on the Profile page" loading="lazy">
        <figcaption><strong>Profilo &rarr; Preferenza Lingua.</strong> Scegli una lingua e fai clic su <strong>Aggiorna Lingua</strong>. Scegliendo <em>Impostazione predefinita di sistema</em> rimuovi la tua preferenza personale.</figcaption>
    </figure>

    <h3>Per gli amministratori: scegliere quali lingue sono disponibili</h3>
    <p>Gli amministratori decidono la <strong>lingua predefinita</strong> (usata per i nuovi utenti e per la pagina di accesso prima che qualcuno effettui il login) e quali lingue tutti sono autorizzati a scegliere.</p>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Generale</span>.</li>
        <li>Trova il menu a discesa <span class="field-label">Lingua Predefinita</span> e scegli il valore predefinito per tutta l'organizzazione.</li>
        <li>Sotto <span class="field-label">Lingue Abilitate</span>, seleziona le lingue che vuoi rendere disponibili. (L'inglese è sempre selezionato e non può essere disabilitato.)</li>
        <li>Fai clic su <span class="btn-label">Salva Configurazione</span>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-general-language.png" alt="Default Language and Enabled Languages settings in Admin General" loading="lazy">
        <figcaption><strong>Admin &rarr; Generale.</strong> Imposta la <strong>Lingua Predefinita</strong> e seleziona le <strong>Lingue Abilitate</strong> tra cui gli utenti possono scegliere.</figcaption>
    </figure>

    <div class="callout callout-warning">
        <strong>Da sapere:</strong> L'interfaccia è tradotta ovunque esista una traduzione per la tua lingua; una stringa non ancora tradotta ricade sull'inglese, quindi potresti ancora vedere qualche etichetta occasionale in inglese. I contenuti che tu o i tuoi fornitori digitate (nomi dei fornitori, note, nomi dei file caricati, risposte a testo libero) vengono sempre mostrati esattamente come immessi. Le <em>domande</em> delle valutazioni dei fornitori possono essere tradotte automaticamente per la visualizzazione quando è configurato un provider AI (vedi <a href="#admin-ai">Integrazione AI</a>); senza di esso rimangono nella lingua in cui sono state scritte. I valori delle risposte memorizzate rimangono sempre in inglese in modo che il punteggio e i report rimangano coerenti tra le lingue.
    </div>
</div>


<!-- ================================================================
     PHONE & VAT QUESTION TYPES + VENDOR DATA IMPROVEMENTS
     ================================================================ -->
<div class="doc-section" id="question-types">
    <h2>Tipi di Domanda Telefono &amp; VAT <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Il <strong>Generatore di Template</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Template di Valutazione</span>) acquisisce due nuovi tipi di domanda che acquisiscono i dettagli di contatto e fiscali in un formato pulito e coerente. Possono essere utilizzati su qualsiasi template di valutazione o onboarding, e come le altre domande possono essere mappati a un campo fornitore in modo che la risposta venga trasmessa al record del fornitore.</p>

    <h3>Telefono</h3>
    <p>Il tipo <strong>Telefono</strong> mostra un selettore del paese con una bandiera e il prefisso internazionale accanto alla casella del numero. Gli Stati Uniti sono elencati per primi; tutti gli altri paesi seguono in ordine alfabetico. Qualunque formato la persona digiti &mdash; <code>314-444-5544</code>, <code>(314)&nbsp;444-5544</code> o <code>3144445544</code> &mdash; il numero viene memorizzato in un unico formato internazionale uniforme (ad esempio, selezionando la bandiera degli Stati Uniti e digitando <code>3144445544</code> si memorizza <code>+13144445544</code>). Il modulo predefinito di Richiesta di Onboarding Fornitore utilizza ora questo tipo per il numero di telefono del contatto principale, e anche il campo telefono dell'<strong>attestazione</strong> della valutazione lo utilizza.</p>

    <h3>VAT (numero di partita IVA UE)</h3>
    <p>Il tipo <strong>VAT</strong> è per i numeri di partita IVA europei. Per proteggersi dagli errori di battitura deve essere <strong>inserito due volte</strong>, e le due voci devono corrispondere prima di essere salvate. Il numero viene memorizzato in una forma coerente (maiuscolo, senza spazi o punteggiatura &mdash; ad esempio <code>DE123456789</code>).</p>
    <ul>
        <li><strong>Validazione live gratuita.</strong> Quando finisci di digitare, la piattaforma verifica il numero rispetto al servizio ufficiale <strong>EU VIES</strong> (il Sistema di Scambio di Informazioni IVA della Commissione Europea). VIES è gratuito, non richiede account e riflette il registro live di ogni stato membro.</li>
        <li><strong>Consultivo, mai bloccante.</strong> Se VIES non riesce a confermare il numero, viene comunque salvato &mdash; un avviso ti chiede semplicemente di verificarlo di nuovo. Se VIES è momentaneamente lento o il registro di un paese è temporaneamente non disponibile, il numero viene salvato e ti viene detto di verificarlo in seguito.</li>
        <li><strong>Dettagli su richiesta.</strong> Quando VIES conferma un numero, accanto ad esso appare un pulsante informazioni (&#9432;). Facendo clic su di esso si apre un pannello che mostra la ragione sociale e l'indirizzo restituiti da VIES.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Mappatura del VAT al record fornitore.</strong> È disponibile un campo dedicato <code>vat_number</code>, quindi una domanda VAT mappata ad esso memorizza il valore nel fornitore. Quando scegli il tipo di domanda VAT nel Generatore di Template, questa mappatura viene selezionata automaticamente per te.
    </div>

    <h3>VAT nella pagina fornitore</h3>
    <p>Il numero di partita IVA del fornitore è mostrato nella scheda <strong>Informazioni Fornitore</strong> nella pagina di onboarding del fornitore. Se nessun VAT è registrato, appare un pulsante <strong>&ldquo;+ Aggiungi VAT&rdquo;</strong> che passa direttamente in modalità di modifica con il campo VAT focalizzato.</p>

    <h3>Trovare fornitori per numero di partita IVA</h3>
    <p>La casella di <strong>ricerca rapida</strong> in alto a destra della piattaforma ora trova corrispondenze anche sul numero di partita IVA, oltre al nome del fornitore, al dominio e allo stakeholder. Le corrispondenze dirette sul nome, dominio o numero di partita IVA del fornitore vengono sempre mostrate per prime.</p>

    <h2>Banner di punteggio Onboarding Procurement <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Quando lo stato di <strong>Onboarding Procurement</strong> di un fornitore è impostato su <strong>No</strong>, un banner ora chiarisce che <em>il punteggio automatico del fornitore è disabilitato finché il fornitore non completa l'Onboarding Procurement</em>. Appare sia nella pagina di onboarding del fornitore che sotto la corrispondente domanda di valutazione, e si aggiorna immediatamente al cambiare della risposta.</p>

    <h2>Invio di una valutazione: prima si verificano i campi obbligatori <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Quando un fornitore fa clic su <strong>Invia</strong> in una valutazione, la piattaforma ora verifica che ogni domanda obbligatoria abbia una risposta <em>prima</em> di richiedere i dettagli di attestazione dell'autore. In precedenza, una risposta mancante veniva segnalata solo dopo che l'attestazione era stata compilata, costringendo a reinserirla.</p>
</div>

<!-- ================================================================
     LARGE DATABASE BACKUPS
     ================================================================ -->
<div class="doc-section" id="admin-backup">
    <h2>Backup &amp; Ripristini di Database di Grandi Dimensioni <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Backup e ripristino (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Backup</span>) ora gestiscono <strong>database di diversi gigabyte</strong> e singoli record che si avvicinano a <strong>1&nbsp;GB</strong> senza che l'operazione venga interrotta da un timeout o dall'esaurimento della memoria. Dietro le quinte, il limite del pacchetto del database, i timeout di rete, la dimensione di caricamento e i limiti di tempo delle richieste sono stati tutti aumentati per gestire dati molto grandi.</p>
    <div class="callout callout-info">
        <strong>Per database molto grandi:</strong> Un backup o un ripristino di un file di diversi gigabyte può richiedere del tempo &mdash; lascia la pagina aperta fino al completamento. I dataset estremamente grandi (decine di gigabyte) è meglio ripristinarli dalla riga di comando del server.
    </div>
</div>


<!-- ================================================================
     GRC MODULE - COMPREHENSIVE DOCUMENTATION
     ================================================================ -->
<div class="doc-section" id="grc-overview">
    <h2>Modulo GRC: Cos'è Governance, Risk &amp; Compliance?</h2>
    <p><strong>GRC</strong> sta per <strong>Governance, Risk e Compliance</strong>. È la pratica di assicurarsi che la tua organizzazione soddisfi i requisiti normativi, segua le migliori pratiche di sicurezza, gestisca i rischi e possa dimostrare la conformità ad auditor e autorità di regolamentazione.</p>

    <p>Il modulo GRC ti aiuta a:</p>
    <ul>
        <li><strong>Valutare la maturità della sicurezza</strong> usando un singolo questionario unificato che mappa contemporaneamente più framework di conformità</li>
        <li><strong>Monitorare la conformità</strong> rispetto a SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls e altro</li>
        <li><strong>Gestire i controlli interni</strong> &mdash; documentare le misure di sicurezza implementate dalla tua organizzazione</li>
        <li><strong>Raccogliere e archiviare prove</strong> &mdash; caricare screenshot, esportazioni di configurazione, documenti di policy e certificati che provano la conformità</li>
        <li><strong>Gestire le policy</strong> &mdash; creare, versionare, approvare e pubblicare le policy di sicurezza organizzative</li>
        <li><strong>Eseguire audit</strong> &mdash; pianificare audit, registrare riscontri, assegnare rimedi e monitorare la chiusura</li>
        <li><strong>Monitorare i rischi</strong> &mdash; mantenere un registro dei rischi con punteggio di probabilità/impatto e piani di trattamento</li>
        <li><strong>Monitorare continuamente</strong> &mdash; configurare controlli automatizzati che verificano i controlli di conformità in base a una pianificazione</li>
    </ul>

    <div class="callout callout-warning">
        <strong>Concetto Importante &mdash; Domande Unificate:</strong> La piattaforma contiene <strong>146 domande di sicurezza unificate</strong> organizzate in <strong>14 domini di sicurezza</strong> (Governance, Identity &amp; Access Management, Sicurezza dei Dati, Sicurezza della Rete, ecc.). Ogni domanda è pre-mappata a requisiti specifici in più framework di conformità. Quando rispondi a una domanda una volta, la risposta si applica automaticamente a ogni framework a cui quella domanda è mappata. Questo elimina la necessità di rispondere alla stessa domanda separatamente per SOC 2, ISO 27001 e PCI DSS.
    </div>
</div>


<div class="doc-section" id="grc-getting-started">
    <h2>Per Iniziare con GRC &mdash; Guida Rapida</h2>
    <p>Se sei completamente nuovo al modulo GRC, segui questi passaggi nell'ordine indicato. Alla fine avrai una valutazione di conformità completata con punteggi su tutti i framework.</p>

    <div class="callout callout-info">
        <strong>Prerequisiti:</strong><br>
        &bull; Devi essere connesso come utente nel gruppo <strong>Administrator</strong> o <strong>Cyber GRC</strong><br>
        &bull; Devi poter vedere <span class="menu-label">Modulo GRC</span> nella barra laterale sinistra<br>
        &bull; Se non lo vedi, chiedi al tuo amministratore di assegnarti al gruppo Cyber GRC (Admin &rarr; Utenti &rarr; fai clic sul pulsante Gruppi accanto al tuo nome &rarr; seleziona "Cyber GRC" &rarr; Salva)
    </div>

    <p>Il flusso di lavoro raccomandato è:</p>
    <ol>
        <li><strong>Crea una Valutazione</strong> &mdash; Questo definisce l'ambito e lo scopo della tua revisione di conformità</li>
        <li><strong>Rispondi alle Domande</strong> &mdash; Lavora attraverso le 146 domande unificate, valutando il tuo livello di maturità per ciascuna</li>
        <li><strong>Carica le Prove</strong> &mdash; Allega documenti, screenshot e file che comprovano le tue risposte</li>
        <li><strong>Visualizza i Tuoi Punteggi</strong> &mdash; Controlla le tue percentuali di conformità nella pagina Framework</li>
        <li><strong>Genera Report</strong> &mdash; Crea report dettagliati di conformità per framework per gli auditor</li>
    </ol>
    <p>Ogni passaggio è spiegato in dettaglio di seguito.</p>
</div>


<div class="doc-section" id="grc-step1">
    <h2>Passo 1: Crea la Tua Prima Valutazione</h2>
    <p>Una <strong>Valutazione</strong> è una revisione di conformità della tua organizzazione. Rappresenta una valutazione in un determinato momento in cui rispondi a domande di sicurezza, registri valutazioni di maturità e raccogli prove. Pensala come una "istantanea della conformità".</p>

    <h3>Come Creare una Nuova Valutazione</h3>
    <ol class="steps">
        <li>Nella barra laterale sinistra, fai clic su <span class="menu-label">Modulo GRC</span> per espanderlo.</li>
        <li>Fai clic sulla sezione <span class="menu-label">Valutazione &amp; Audit</span> per espanderla.</li>
        <li>Fai clic su <span class="menu-label">Questionario di Valutazione</span>. Si apre la pagina principale della valutazione.</li>
        <li>In cima alla pagina, vedrai un pulsante <span class="btn-label">+ Nuova Valutazione</span>. Fai clic su di esso.</li>
        <li>Apparirà un modulo. Compila i seguenti campi:
            <ul>
                <li><span class="field-label">Titolo</span> &mdash; Dai alla tua valutazione un nome descrittivo. Esempio: <code>2026 Annual Security Assessment - ACME Corp</code></li>
                <li><span class="field-label">Tipo di Valutazione</span> &mdash; Seleziona il tipo di valutazione:
                    <ul>
                        <li><strong>Iniziale</strong> &mdash; La tua prima valutazione in assoluto (consigliata per i nuovi utenti)</li>
                        <li><strong>Periodica</strong> &mdash; Una valutazione ricorrente regolare (es. revisione annuale)</li>
                        <li><strong>Mirata</strong> &mdash; Una valutazione focalizzata su un'area specifica</li>
                        <li><strong>Pre-Audit</strong> &mdash; Preparazione prima di un audit formale</li>
                        <li><strong>Certificazione</strong> &mdash; Valutazione per scopi di certificazione (es. SOC 2 Type II)</li>
                    </ul>
                </li>
                <li><span class="field-label">Ambito</span> &mdash; Seleziona o descrivi l'ambito organizzativo. Definisce quale parte della tua organizzazione viene valutata (es. "Tutti i sistemi IT" o "Infrastruttura Cloud").</li>
                <li><span class="field-label">Auditor Principale</span> &mdash; Seleziona la persona che guida questa valutazione. Il menu a discesa mostra solo gli utenti nei gruppi Administrator o Cyber GRC.</li>
                <li><span class="field-label">Data di Inizio Prevista</span> &mdash; Quando prevedi di iniziare la valutazione.</li>
                <li><span class="field-label">Data di Fine Prevista</span> &mdash; La data di completamento prevista.</li>
            </ul>
        </li>
        <li>Fai clic su <span class="btn-label">Crea Valutazione</span>.</li>
        <li>La tua nuova valutazione viene creata con lo stato <span class="status-label">Bozza</span>. Puoi ora iniziare a rispondere alle domande.</li>
    </ol>

    <div class="example-box">
        <strong>Esempio:</strong> Stai conducendo la prima revisione annuale della sicurezza della tua organizzazione.<br><br>
        &bull; Titolo: <code>2026 Annual Security Assessment</code><br>
        &bull; Tipo: <code>Iniziale</code><br>
        &bull; Ambito: <code>Tutti i Sistemi IT Aziendali</code><br>
        &bull; Auditor Principale: <code>Jane Smith</code><br>
        &bull; Data di Inizio: <code>1 marzo 2026</code><br>
        &bull; Data di Fine: <code>30 aprile 2026</code>
    </div>

    <h3>Stati della Valutazione</h3>
    <table class="doc-table">
        <tr><th>Stato</th><th>Significato</th></tr>
        <tr><td><span class="status-label">Bozza</span></td><td>La valutazione è stata creata ma il lavoro non è ancora iniziato. Le domande possono essere risposte.</td></tr>
        <tr><td><span class="status-label">In Corso</span></td><td>Valutazione attiva &mdash; i membri del team stanno rispondendo alle domande e caricando prove.</td></tr>
        <tr><td><span class="status-label">In Revisione</span></td><td>Tutte le domande hanno una risposta &mdash; un auditor principale o un validatore sta esaminando le risposte.</td></tr>
        <tr><td><span class="status-label">Completata</span></td><td>La valutazione è terminata e finalizzata. Le risposte sono bloccate.</td></tr>
        <tr><td><span class="status-label">Archiviata</span></td><td>Valutazione storica conservata per i registri. Non più attiva.</td></tr>
    </table>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment.png" alt="Assessment list on the Assessment Questionnaire page" loading="lazy">
        <figcaption><strong>Modulo GRC &rarr; Questionario di Valutazione.</strong> Ogni valutazione è elencata con il suo riferimento, titolo, tipo, stato, auditor principale, punteggio CSF corrente e % di conformità, e data prevista. Usa <span class="btn-label">+ Nuova Valutazione</span> per avviarne una, o <span class="btn-label">Apri</span> per continuare a rispondere a una esistente. Le schede di stato nella parte superiore filtrano l'elenco.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step2">
    <h2>Passo 2: Rispondi alle Domande della Valutazione</h2>
    <p>Una volta creata una valutazione, devi rispondere alle 146 domande di sicurezza unificate. Ogni domanda appartiene a uno dei 14 domini di sicurezza.</p>

    <h3>I 14 Domini di Sicurezza</h3>
    <table class="doc-table">
        <tr><th>Codice</th><th>Nome del Dominio</th><th>Domande</th><th>Cosa Copre</th></tr>
        <tr><td><code>GOV</code></td><td>Governance &amp; Leadership</td><td>12</td><td>Leadership del programma di sicurezza, strategia, budget, reportistica al consiglio</td></tr>
        <tr><td><code>IAM</code></td><td>Identity &amp; Access Management</td><td>14</td><td>Account utente, autenticazione, controlli di accesso, accesso privilegiato</td></tr>
        <tr><td><code>DSP</code></td><td>Data Security &amp; Privacy</td><td>12</td><td>Classificazione dei dati, crittografia, privacy, prevenzione della perdita di dati</td></tr>
        <tr><td><code>EPS</code></td><td>Endpoint &amp; Platform Security</td><td>10</td><td>Laptop, server, dispositivi mobili, patch, EDR</td></tr>
        <tr><td><code>NET</code></td><td>Network Security</td><td>11</td><td>Firewall, segmentazione, VPN, sicurezza DNS, Wi-Fi</td></tr>
        <tr><td><code>APS</code></td><td>Application Security</td><td>10</td><td>Sviluppo sicuro, revisioni del codice, sicurezza API, WAF</td></tr>
        <tr><td><code>OPS</code></td><td>Security Operations</td><td>12</td><td>SIEM, logging, monitoraggio, scansione delle vulnerabilità, SOC</td></tr>
        <tr><td><code>INC</code></td><td>Incident Management</td><td>10</td><td>Piani di risposta agli incidenti, esercitazioni tabletop, notifica di violazione</td></tr>
        <tr><td><code>SCM</code></td><td>Supply Chain &amp; Third Party</td><td>10</td><td>Gestione dei fornitori, rischio della catena di approvvigionamento, contratti</td></tr>
        <tr><td><code>PHY</code></td><td>Physical &amp; Environmental</td><td>8</td><td>Data center, badge di accesso, CCTV, controlli ambientali</td></tr>
        <tr><td><code>HRS</code></td><td>Human Resources Security</td><td>10</td><td>Verifiche dei precedenti, formazione sulla sicurezza, procedure di cessazione</td></tr>
        <tr><td><code>BCP</code></td><td>Business Continuity</td><td>10</td><td>Backup, disaster recovery, test BCP, RTO/RPO</td></tr>
        <tr><td><code>CRY</code></td><td>Cryptography &amp; Key Management</td><td>8</td><td>Standard di crittografia, rotazione delle chiavi, gestione dei certificati</td></tr>
        <tr><td><code>CMP</code></td><td>Compliance &amp; Assurance</td><td>9</td><td>Conformità normativa, audit interno, prontezza all'audit esterno</td></tr>
    </table>

    <h3>Come Rispondere alle Domande</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Valutazione &amp; Audit</span> &rarr; <span class="menu-label">Questionario di Valutazione</span>.</li>
        <li>Se hai più valutazioni, seleziona quella corretta dal menu a discesa in cima alla pagina.</li>
        <li>Vedrai elencati i 14 domini di sicurezza. Fai clic sul nome di un dominio (es. <strong>GOV - Governance &amp; Leadership</strong>) per espanderlo e vedere le sue domande.</li>
        <li>Per ogni domanda, devi fornire due informazioni:
            <ul>
                <li><span class="field-label">Livello di Maturità</span> (1-4) &mdash; Quanto è matura l'implementazione di questo controllo nella tua organizzazione?
                    <ul>
                        <li><strong>1 &mdash; Iniziale/Ad Hoc:</strong> Nessun processo formale. Eseguito in modo inconsistente o per niente.</li>
                        <li><strong>2 &mdash; In Sviluppo:</strong> Esistono alcuni processi ma non vengono seguiti in modo coerente. Parzialmente documentato.</li>
                        <li><strong>3 &mdash; Definito:</strong> Processi formali e documentati sono in atto e seguiti in modo coerente.</li>
                        <li><strong>4 &mdash; Gestito/Ottimizzato:</strong> I processi sono misurati, monitorati e continuamente migliorati.</li>
                    </ul>
                </li>
                <li><span class="field-label">Stato di Conformità</span> &mdash; Il tuo stato di conformità per questa domanda:
                    <ul>
                        <li><strong>Conforme</strong> &mdash; Completamente implementato e soddisfa il requisito</li>
                        <li><strong>Parziale</strong> &mdash; Parzialmente implementato; rimangono alcune lacune</li>
                        <li><strong>Non Conforme</strong> &mdash; Non implementato o non soddisfa il requisito</li>
                        <li><strong>Non Applicabile</strong> &mdash; Questa domanda non si applica alla tua organizzazione</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li>Facoltativamente, aggiungi <span class="field-label">Note</span> per spiegare la tua risposta. È altamente raccomandato &mdash; gli auditor vorranno vedere il tuo ragionamento.</li>
        <li>Le tue risposte si <strong>salvano automaticamente</strong> mentre lavori. Non è necessario fare clic su un pulsante di salvataggio.</li>
        <li>Continua a rispondere alle domande in tutti i 14 domini. Non è necessario completare tutto in una sola sessione &mdash; torna in qualsiasi momento per riprendere.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Suggerimento &mdash; La maturità determina la conformità:</strong> Quando imposti un livello di maturità, il sistema può derivare automaticamente lo stato di conformità: Maturità 3-4 = Conforme, Maturità 2 = Parziale, Maturità 1 = Non Conforme. Puoi ignorare questa impostazione se necessario.
    </div>

    <div class="callout callout-warning">
        <strong>Importante:</strong> Ogni domanda a cui rispondi mappa ai requisiti su più framework. Ad esempio, rispondere a una domanda sull'"Autenticazione a più fattori" (nel dominio IAM) aggiorna simultaneamente i tuoi punteggi di conformità per SOC 2, ISO 27001, PCI DSS, NIST CSF e CMMC. Non è mai necessario rispondere allo stesso concetto due volte.
    </div>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment-questions.png" alt="Answering questions in the assessment questionnaire" loading="lazy">
        <figcaption><strong>Rispondere al questionario.</strong> L'intestazione tiene traccia del <em>Progresso</em>, della <em>Maturità CSF</em> in tempo reale e della <em>Conformità</em> mentre lavori. Le schede del dominio (GOV, IAM, DSP, &hellip;) mostrano ciascuna il punteggio corrente di quel dominio; fai clic su una per passare alle sue domande. Per ogni domanda imposti un livello di <strong>Maturità</strong> (1&ndash;4 o N/A) e uno stato di <strong>Conformità</strong> &mdash; le risposte si salvano automaticamente. Usa <span class="btn-label">Mostra Domande Senza Risposta</span> per trovare quelle rimanenti.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step3">
    <h2>Passo 3: Carica le Prove</h2>
    <p>Le prove dimostrano che le tue risposte sono accurate. Gli auditor si aspetteranno di vedere prove per ogni affermazione di conformità. Le prove possono includere screenshot, esportazioni di configurazione, documenti di policy, log di audit, certificati e altro.</p>

    <h3>Come Caricare le Prove Durante una Valutazione</h3>
    <ol class="steps">
        <li>Mentre rispondi a una domanda nel <span class="menu-label">Questionario di Valutazione</span>, cerca la sezione <strong>Prove</strong> sotto l'area di risposta alla domanda.</li>
        <li>Fai clic su <span class="btn-label">Carica Prova</span> o sull'icona allegato.</li>
        <li>Seleziona un file dal tuo computer. I tipi supportati includono PDF, immagini (PNG, JPG), documenti Word, fogli di calcolo Excel e file di testo.</li>
        <li>Dai alla prova un <span class="field-label">Titolo</span> descrittivo (es. "Screenshot Configurazione MFA - Console Admin Okta").</li>
        <li>La prova viene automaticamente collegata alla domanda di valutazione corrente.</li>
        <li>Puoi caricare più file di prova per domanda.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Sicurezza:</strong> Tutti i file di prova caricati vengono crittografati (AES-256-CBC) prima di essere archiviati nel database. Quando scarichi le prove, vengono decrittografate al momento. Questo garantisce che i documenti di conformità sensibili siano protetti a riposo.
    </div>

    <h3>Libreria delle Prove</h3>
    <p>Puoi anche gestire le prove separatamente tramite <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Prove &amp; Monitoraggio</span> &rarr; <span class="menu-label">Libreria delle Prove</span>. Questa pagina mostra tutte le prove di tutte le valutazioni e i controlli, con filtro per tipo, stato e data di scadenza.</p>
</div>


<div class="doc-section" id="grc-step4">
    <h2>Passo 4: Visualizza i Tuoi Punteggi di Conformità</h2>
    <p>Man mano che rispondi alle domande, la piattaforma calcola in tempo reale la tua percentuale di conformità per ogni framework.</p>

    <h3>Visualizzazione dei Punteggi nella Pagina Framework</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Conformità</span> &rarr; <span class="menu-label">Framework</span>.</li>
        <li>In cima alla pagina, vedrai un menu a discesa <span class="field-label">Valutazione</span>. Seleziona la valutazione di cui vuoi vedere i punteggi. Per impostazione predefinita, è selezionata la valutazione più recente.</li>
        <li>Sotto il menu a discesa, vedrai le schede del framework &mdash; una per ogni framework di conformità che ha domande mappate ad esso. Ogni scheda mostra:
            <ul>
                <li>Un <strong>grafico a ciambella</strong> che mostra la percentuale di conformità complessiva (es. 75%)</li>
                <li>Il <strong>codice e il nome del framework</strong> (es. "SOC2 &mdash; SOC 2 Type II")</li>
                <li>Il punteggio di <strong>Maturità Media</strong> (se esistono dati di maturità, visualizzato come es. "3,50 / 4,00")</li>
                <li>Conteggi metrici: <strong>Conforme</strong>, <strong>Parziale</strong>, <strong>Non Conforme</strong> e <strong>Totale Mappato</strong></li>
            </ul>
        </li>
        <li>Fai clic su una qualsiasi scheda del framework per aprire il <strong>Report di Conformità</strong> dettagliato per quel framework.</li>
    </ol>

    <h3>Calcolo della Percentuale di Conformità</h3>
    <p>La percentuale di conformità viene calcolata come:</p>
    <div class="example-box">
        <strong>Formula:</strong> <code>(Conforme + Parziale &times; 0,5) &divide; Requisiti Applicabili &times; 100</code><br><br>
        &bull; I requisiti <strong>Conformi</strong> contano come 100% completi<br>
        &bull; I requisiti <strong>Parziali</strong> contano come 50% completi<br>
        &bull; I requisiti <strong>Non Applicabili</strong> sono esclusi dal calcolo<br>
        &bull; I requisiti <strong>Non Conformi</strong> e <strong>Non Valutati</strong> contano come 0%
    </div>

    <h3>Framework Attualmente Supportati</h3>
    <table class="doc-table">
        <tr><th>Framework</th><th>Versione</th><th>Domande Mappate</th></tr>
        <tr><td>NIST Cybersecurity Framework (CSF)</td><td>2.0</td><td>146</td></tr>
        <tr><td>ISO/IEC 27001</td><td>2022</td><td>146</td></tr>
        <tr><td>SOC 2 Type II</td><td>2017</td><td>146</td></tr>
        <tr><td>PCI DSS</td><td>4.0</td><td>132</td></tr>
        <tr><td>CMMC / NIST 800-171</td><td>v2.0</td><td>97</td></tr>
        <tr><td>CIS Controls</td><td>v8</td><td>95</td></tr>
        <tr><td>NIST SP 800-171</td><td>Rev 2</td><td>90</td></tr>
        <tr><td>HIPAA Security Rule</td><td>2013</td><td>61</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-step5">
    <h2>Passo 5: Genera un Report di Conformità per Framework</h2>
    <p>Una volta risposto alle domande, puoi generare un report di conformità dettagliato per qualsiasi framework. Questo report è adatto per essere condiviso con auditor, autorità di regolamentazione o management.</p>

    <h3>Come Generare un Report</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Conformità</span> &rarr; <span class="menu-label">Framework</span>.</li>
        <li>Seleziona la tua valutazione dal menu a discesa <span class="field-label">Valutazione</span> in cima.</li>
        <li>Fai clic sulla scheda del framework su cui vuoi fare il report (es. "SOC2 &mdash; SOC 2 Type II").</li>
        <li>Si apre la pagina <strong>Report di Conformità del Framework</strong>, che mostra:
            <ul>
                <li><strong>Intestazione del Report</strong> &mdash; Nome del framework, titolo della valutazione, tipo, stato, ambito, auditor principale, date e percentuale di conformità complessiva</li>
                <li><strong>Statistiche Riassuntive</strong> &mdash; Schede cliccabili che mostrano il Totale dei Requisiti, Conformi, Parziali, Non Conformi, Non Valutati e conteggi N/A</li>
                <li><strong>Schede dei Requisiti</strong> &mdash; Una scheda per ogni requisito del framework, che mostra il riferimento del requisito, il titolo, il badge di stato e tutte le domande mappate con le relative risposte</li>
            </ul>
        </li>
        <li>Per <strong>filtrare i requisiti per stato</strong>, fai clic su una delle schede delle statistiche riassuntive in cima. Ad esempio, fai clic su <strong>Non Conforme</strong> per mostrare solo i requisiti non conformi. Fai di nuovo clic (o fai clic su "Totale Requisiti") per mostrare tutti.</li>
        <li>Per <strong>stampare il report</strong>, fai clic sul pulsante <span class="btn-label">Stampa Report</span> in cima. Si aprirà la finestra di dialogo di stampa del browser. Puoi stampare su carta o selezionare "Salva come PDF" per creare un file PDF.</li>
    </ol>

    <h3>Cosa Mostra Ogni Scheda del Requisito</h3>
    <p>Per ogni requisito nel report, vedrai:</p>
    <ul>
        <li><strong>Riferimento del Requisito</strong> &mdash; Il numero di riferimento ufficiale (es. "CC6.1" per SOC 2)</li>
        <li><strong>Titolo del Requisito</strong> &mdash; Cosa dice il requisito</li>
        <li><strong>Badge di Stato</strong> &mdash; Codificato a colori: verde (Conforme), ambra (Parziale), rosso (Non Conforme), grigio (Non Valutato / N/A)</li>
        <li><strong>Domande Mappate</strong> &mdash; Ogni domanda che mappa a questo requisito, che mostra:
            <ul>
                <li>Riferimento e testo della domanda</li>
                <li>Livello di maturità (1-4) con una barra visiva</li>
                <li>Stato di conformità</li>
                <li>Stato di validazione (In attesa, Validato, Rifiutato, Necessita di Revisione)</li>
                <li>Nome e data del valutatore</li>
                <li>Forza della mappatura (Esatta, Forte, Parziale, Correlata)</li>
                <li>Note del valutatore</li>
                <li>Note di validazione</li>
                <li>Allegati di prova (con link di download)</li>
            </ul>
        </li>
    </ul>
</div>


<div class="doc-section" id="grc-fairscore">
    <h2>Dashboard del Punteggio di Maturità CSF</h2>
    <p>La pagina <strong>Punteggio di Maturità CSF</strong> fornisce una dashboard visiva che mostra la maturità della tua organizzazione in tutti i 14 domini di sicurezza, allineata al NIST Cybersecurity Framework.</p>

    <h3>Come Accedere</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Valutazione &amp; Audit</span> &rarr; <span class="menu-label">Punteggio di Maturità CSF</span>.</li>
        <li>Se hai più valutazioni, seleziona quella desiderata dal menu a discesa.</li>
        <li>La pagina mostra:
            <ul>
                <li><strong>Punteggio FAIR Complessivo</strong> &mdash; Un punteggio di maturità medio ponderato su tutti i domini</li>
                <li><strong>Grafico Radar</strong> &mdash; Un grafico visivo a ragno/radar che traccia i tuoi punteggi su tutti i 14 domini</li>
                <li><strong>Schede Punteggio del Dominio</strong> &mdash; Schede individuali per ogni dominio che mostrano maturità media, domande risposte e suddivisione della conformità</li>
                <li><strong>Barre di Conformità del Framework</strong> &mdash; Barre orizzontali che mostrano le percentuali di conformità per framework</li>
                <li><strong>Riepilogo Analisi delle Lacune</strong> &mdash; Domini in cui i punteggi sono al di sotto del target</li>
            </ul>
        </li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grc-fairscore.png" alt="CSF Maturity Score dashboard with radar chart" loading="lazy">
        <figcaption><strong>Modulo GRC &rarr; Punteggio di Maturità CSF.</strong> Le quattro tessere principali &mdash; <em>Punteggio di Maturità CSF</em> (scala 1&ndash;4), <em>Tasso di Conformità</em>, <em>Domande Risposte</em> e <em>Lacune Trovate</em> &mdash; riassumono la tua postura a colpo d'occhio. Il <strong>Radar di Maturità dei Domini di Sicurezza</strong> traccia tutti i 14 domini, e l'elenco a destra fornisce il punteggio medio esatto di ogni dominio. Scegli la valutazione che vuoi dal menu a discesa in cima.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-gaps">
    <h2>Analisi delle Lacune</h2>
    <p>La pagina <strong>Analisi delle Lacune</strong> raccoglie tutte le debolezze riscontrate durante una valutazione &mdash; ogni domanda con risposta <strong>Non Conforme</strong> o <strong>Parziale</strong> &mdash; in un elenco di lavoro prioritizzato. Risponde alla domanda "dove siamo carenti e cosa influenza ogni carenza?"</p>

    <h3>Come Accedere</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Valutazione &amp; Audit</span> &rarr; <span class="menu-label">Lacune</span>.</li>
        <li>Seleziona la valutazione che vuoi analizzare dal menu a discesa <span class="field-label">Valutazione</span>.</li>
    </ol>

    <h3>Cosa Mostra la Pagina</h3>
    <p>Quattro tessere riassuntive in cima contano le tue <strong>Lacune Totali</strong>, <strong>Non Conformi</strong>, <strong>Parziali</strong> e le lacune <strong>Con Rischio Collegato</strong>. Sotto di esse, ogni lacuna è elencata come riga con:</p>
    <ul>
        <li><strong>Gravità</strong> &mdash; un badge: <em>Non Conforme</em> (rosso) o <em>Parziale</em> (ambra).</li>
        <li><strong>Dominio</strong> e <strong>Rif.</strong> &mdash; il dominio di sicurezza e il riferimento esatto della domanda (es. <code>GOV-08</code>).</li>
        <li><strong>Riscontro</strong> &mdash; il testo della domanda che descrive cosa manca.</li>
        <li><strong>Impatto sul Framework</strong> &mdash; badge per ogni requisito del framework influenzato da questa lacuna, in modo da poter vedere a colpo d'occhio se una singola correzione migliora SOC 2, ISO 27001, PCI DSS e altro ancora contemporaneamente.</li>
        <li><strong>Rischio</strong> &mdash; se è stato registrato un rischio per questa lacuna, e un'azione <span class="btn-label">Visualizza</span> per aprire il dettaglio completo.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/grc-gaps.png" alt="Gap Analysis page listing non-conforming and partial findings" loading="lazy">
        <figcaption><strong>Modulo GRC &rarr; Lacune.</strong> Ogni risposta non conforme o parziale diventa una lacuna. La colonna <strong>Impatto sul Framework</strong> mostra quali requisiti su ogni framework la lacuna tocca &mdash; chiudere una lacuna può migliorare più framework contemporaneamente.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-frameworks">
    <h2>Pagina Framework</h2>
    <p>La pagina <strong>Framework</strong> è il tuo hub centrale per visualizzare lo stato di conformità su tutti i framework supportati. Mostra dati di conformità basati sulla valutazione.</p>

    <h3>Come Usarla</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Conformità</span> &rarr; <span class="menu-label">Framework</span>.</li>
        <li>Seleziona una valutazione dal menu a discesa <span class="field-label">Valutazione</span>. La pagina predefinita è la valutazione più recente.</li>
        <li>La pagina mostra le schede del framework in una griglia. Appaiono solo i framework con domande mappate. Ogni scheda mostra la percentuale di conformità, il punteggio di maturità e i conteggi metrici.</li>
        <li>Fai clic su una scheda del framework per aprire il report di conformità dettagliato.</li>
    </ol>

    <figure class="doc-figure">
        <img src="app/docs/grc-frameworks.png" alt="Compliance Frameworks page with per-framework compliance cards" loading="lazy">
        <figcaption><strong>Modulo GRC &rarr; Framework.</strong> Le tessere superiori contano i tuoi framework, la prontezza media, i requisiti totali e quanti <em>necessitano di attenzione</em>. Ogni scheda mostra il grafico a ciambella di conformità di un framework, la sua maturità media e la suddivisione Conforme / Parziale / Non Conforme / Totale Mappato. Fai clic su qualsiasi scheda per aprire il report completo di conformità di quel framework.</figcaption>
    </figure>

    <h3>Albero dei Requisiti del Framework</h3>
    <p>Se navighi in questa pagina <em>senza</em> selezionare una valutazione (o facendo clic su un collegamento del framework da altrove), vedrai la visualizzazione <strong>Albero dei Requisiti</strong>. Questa mostra la struttura gerarchica di tutti i requisiti all'interno di un framework, insieme ai controlli mappati e allo stato di implementazione. Gli amministratori e gli utenti Cyber GRC possono aggiungere, modificare ed eliminare requisiti personalizzati qui.</p>
</div>


<div class="doc-section" id="grc-controls">
    <h2>Controlli Interni</h2>
    <p>I <strong>Controlli Interni</strong> sono le misure di sicurezza specifiche che la tua organizzazione ha implementato. Esempi: "Autenticazione a più fattori su tutti i sistemi," "Backup crittografati giornalieri," "Test di penetrazione annuale."</p>

    <h3>Come Creare un Controllo</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Conformità</span> &rarr; <span class="menu-label">Controlli Interni</span>.</li>
        <li>Fai clic su <span class="btn-label">+ Nuovo Controllo</span>.</li>
        <li>Compila i campi:
            <ul>
                <li><span class="field-label">Titolo del Controllo</span> &mdash; Un nome breve (es. "MFA per tutti gli account utente")</li>
                <li><span class="field-label">Descrizione</span> &mdash; Descrizione dettagliata di cosa fa questo controllo</li>
                <li><span class="field-label">Tipo di Controllo</span> &mdash; Preventivo, Investigativo, Correttivo o Direttivo</li>
                <li><span class="field-label">Categoria</span> &mdash; Tecnica, Amministrativa o Fisica</li>
                <li><span class="field-label">Stato di Implementazione</span> &mdash; Pianificato, In Corso, Implementato o Non Applicabile</li>
                <li><span class="field-label">Efficacia</span> &mdash; Non Testato, Inefficace, Parzialmente Efficace o Efficace</li>
                <li><span class="field-label">Livello di Rischio</span> &mdash; Basso, Medio, Alto o Critico</li>
                <li><span class="field-label">Responsabile</span> &mdash; La persona responsabile (limitata ai membri dei gruppi Administrator e Cyber GRC)</li>
                <li><span class="field-label">Frequenza di Test</span> &mdash; Con quale frequenza viene testato questo controllo (Giornaliera, Settimanale, Mensile, ecc.)</li>
            </ul>
        </li>
        <li>Sotto <strong>Mappatura del Framework</strong>, seleziona i requisiti del framework che questo controllo soddisfa. Puoi mappare un controllo ai requisiti su più framework.</li>
        <li>Fai clic su <span class="btn-label">Salva</span>.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Vantaggio Chiave &mdash; Mappatura Cross-Framework:</strong> Un singolo controllo come "MFA" può soddisfare i requisiti di SOC 2 (CC6.1), ISO 27001 (A.8.5), PCI DSS (8.4.2) e NIST CSF (PR.AC-7) contemporaneamente. Mappalo una volta e copre tutti i framework.
    </div>
</div>


<div class="doc-section" id="grc-crosswalk">
    <h2>Crosswalk dei Framework</h2>
    <p>Il <strong>Crosswalk dei Framework</strong> mostra come la conformità a un framework fornisce automaticamente copertura per un altro. Ad esempio, se sei conforme a SOC 2, quanto di ISO 27001 copri già?</p>

    <h3>Come Usarlo</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Conformità</span> &rarr; <span class="menu-label">Crosswalk dei Framework</span>.</li>
        <li>Seleziona un <span class="field-label">Framework di Origine</span> (il framework che hai già completato, es. "SOC 2").</li>
        <li>Seleziona un <span class="field-label">Framework di Destinazione</span> (il framework con cui vuoi confrontare, es. "ISO 27001").</li>
        <li>La tabella di crosswalk mostra quali requisiti di destinazione sono coperti dai tuoi controlli di origine e quali hanno lacune.</li>
    </ol>
</div>


<div class="doc-section" id="grc-evidence">
    <h2>Libreria delle Prove</h2>
    <p>La <strong>Libreria delle Prove</strong> è un archivio centralizzato per tutte le prove di conformità della tua organizzazione.</p>

    <h3>Come Caricare le Prove</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Prove &amp; Monitoraggio</span> &rarr; <span class="menu-label">Libreria delle Prove</span>.</li>
        <li>Fai clic su <span class="btn-label">+ Carica Prova</span>.</li>
        <li>Compila: <span class="field-label">Titolo</span>, <span class="field-label">Tipo di Prova</span> (screenshot, documento, certificato, configurazione, report, ecc.), <span class="field-label">Descrizione</span>, e facoltativamente una <span class="field-label">Data di Scadenza</span>.</li>
        <li>Seleziona il file da caricare.</li>
        <li>Fai clic su <span class="btn-label">Carica</span>. Il file viene crittografato e archiviato in modo sicuro.</li>
        <li>Puoi quindi collegare questa prova a controlli specifici o a risposte della valutazione.</li>
    </ol>

    <h3>Stati delle Prove</h3>
    <table class="doc-table">
        <tr><th>Stato</th><th>Significato</th></tr>
        <tr><td><strong>Corrente</strong></td><td>Prova attiva e valida</td></tr>
        <tr><td><strong>Scaduta</strong></td><td>Oltre la data di scadenza &mdash; deve essere aggiornata</td></tr>
        <tr><td><strong>Sostituita</strong></td><td>Rimpiazzata da prove più recenti</td></tr>
        <tr><td><strong>Bozza</strong></td><td>Caricata ma non ancora revisionata o finalizzata</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-policies">
    <h2>Gestione delle Policy</h2>
    <p>La pagina <strong>Policy</strong> fornisce un ciclo di vita completo delle policy &mdash; dalla bozza fino all'approvazione, alla pubblicazione e alla revisione periodica.</p>

    <h3>Come Creare una Policy</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Gestione Policy</span> &rarr; <span class="menu-label">Policy</span>.</li>
        <li>Fai clic su <span class="btn-label">+ Nuova Policy</span>.</li>
        <li>Compila: <span class="field-label">Titolo</span>, <span class="field-label">Categoria</span> (Sicurezza, Privacy, Conformità, Operativa, HR, IT, ecc.), <span class="field-label">Frequenza di Revisione</span> (con quale frequenza la policy deve essere rivista).</li>
        <li>Scrivi il contenuto della policy usando l'editor di testo ricco.</li>
        <li>Fai clic su <span class="btn-label">Salva</span>. La policy viene creata con lo stato <span class="status-label">Bozza</span>.</li>
        <li>Quando è pronta, invia per <strong>Revisione</strong> &rarr; <strong>Approvazione</strong> &rarr; <strong>Pubblicazione</strong>.</li>
    </ol>

    <h3>Ciclo di Vita della Policy</h3>
    <p><span class="status-label">Bozza</span> &rarr; <span class="status-label">Revisione</span> &rarr; <span class="status-label">Approvata</span> &rarr; <span class="status-label">Pubblicata</span> &rarr; (Revisione Periodica o <span class="status-label">Ritirata</span>)</p>
</div>


<div class="doc-section" id="grc-audits">
    <h2>Audit &amp; Riscontri</h2>
    <p>La pagina <strong>Audit</strong> gestisce il ciclo di vita completo dell'audit &mdash; dalla pianificazione attraverso il lavoro sul campo, i riscontri, la rimediazione e la chiusura.</p>

    <h3>Come Creare un Audit</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Valutazione &amp; Audit</span> &rarr; <span class="menu-label">Audit</span>.</li>
        <li>Fai clic su <span class="btn-label">+ Nuovo Audit</span>.</li>
        <li>Compila: <span class="field-label">Titolo</span>, <span class="field-label">Tipo di Audit</span> (Interno, Esterno, Certificazione, Sorveglianza, Prontezza), <span class="field-label">Framework</span>, <span class="field-label">Auditor Principale</span>, <span class="field-label">Date Previste di Inizio/Fine</span>.</li>
        <li>Fai clic su <span class="btn-label">Crea</span>.</li>
    </ol>

    <h3>Registrazione dei Riscontri</h3>
    <ol class="steps">
        <li>Apri un audit e fai clic su <span class="btn-label">+ Aggiungi Riscontro</span>.</li>
        <li>Compila: <span class="field-label">Titolo</span>, <span class="field-label">Gravità</span> (Informativo, Basso, Medio, Alto, Critico), <span class="field-label">Tipo di Riscontro</span> (Non Conformità, Osservazione, Opportunità, Punto di Forza) e <span class="field-label">Descrizione</span>.</li>
        <li>Mappa il riscontro a requisiti o controlli specifici del framework.</li>
        <li>Assegna la rimediazione a un membro del team con una data di scadenza.</li>
        <li>Monitora il progresso della rimediazione fino allo stato <strong>Verificato Chiuso</strong>.</li>
    </ol>

    <h3>Stati degli Audit</h3>
    <table class="doc-table">
        <tr><th>Stato</th><th>Significato</th></tr>
        <tr><td><strong>Pianificazione</strong></td><td>Definizione di ambito, obiettivi e pianificazione</td></tr>
        <tr><td><strong>Lavoro sul Campo</strong></td><td>Test attivi, revisione delle prove e interviste</td></tr>
        <tr><td><strong>Reportistica</strong></td><td>Redazione del report di audit e documentazione dei riscontri</td></tr>
        <tr><td><strong>Rimediazione</strong></td><td>I riscontri sono stati segnalati; il team sta risolvendo i problemi</td></tr>
        <tr><td><strong>Chiuso</strong></td><td>Tutti i riscontri risolti e audit completato</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-risks">
    <h2>Registro dei Rischi</h2>
    <p>Il <strong>Registro dei Rischi</strong> traccia i rischi organizzativi con punteggi di probabilità/impatto, piani di trattamento e collegamenti ai controlli.</p>

    <h3>Come Aggiungere un Rischio</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Valutazione &amp; Audit</span> &rarr; <span class="menu-label">Registro dei Rischi</span>.</li>
        <li>Fai clic su <span class="btn-label">+ Nuovo Rischio</span>.</li>
        <li>Compila: <span class="field-label">Titolo</span>, <span class="field-label">Descrizione</span>, <span class="field-label">Categoria</span> (Strategico, Operativo, Finanziario, Conformità, Reputazionale, Tecnologico, Terze Parti).</li>
        <li>Imposta <span class="field-label">Probabilità</span> (Raro, Improbabile, Possibile, Probabile, Quasi Certo) e <span class="field-label">Impatto</span> (Irrilevante, Minore, Moderato, Grave, Catastrofico).</li>
        <li>Il sistema calcola il <strong>Punteggio di Rischio Intrinseco</strong> (Probabilità &times; Impatto, su una scala da 1 a 25).</li>
        <li>Seleziona una <span class="field-label">Strategia di Trattamento</span>: Accettare, Mitigare, Trasferire o Evitare.</li>
        <li>Collega i controlli interni pertinenti per mostrare come il rischio viene mitigato. Il sistema calcola il <strong>Punteggio di Rischio Residuo</strong> dopo i controlli.</li>
    </ol>
</div>


<div class="doc-section" id="grc-monitors">
    <h2>Monitor Continui</h2>
    <p>I <strong>Monitor Continui</strong> sono controlli automatizzati che verificano i tuoi controlli di sicurezza in base a una pianificazione (oraria, giornaliera, settimanale o mensile).</p>

    <h3>Come Creare un Monitor</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Prove &amp; Monitoraggio</span> &rarr; <span class="menu-label">Monitor Continui</span>.</li>
        <li>Fai clic su <span class="btn-label">+ Nuovo Monitor</span>.</li>
        <li>Compila: <span class="field-label">Titolo</span>, <span class="field-label">Tipo di Controllo</span>, <span class="field-label">Frequenza</span> (Oraria, Giornaliera, Settimanale, Mensile) e la <span class="field-label">Configurazione del Collettore</span> (impostazioni JSON per il controllo).</li>
        <li>Collega il monitor a un controllo interno.</li>
        <li>Abilita il monitor. Verrà eseguito automaticamente in base alla pianificazione configurata.</li>
        <li>Visualizza i risultati (Superato, Fallito, Errore, Avviso) e la cronologia di esecuzione nella pagina dei dettagli del monitor.</li>
    </ol>
</div>


<div class="doc-section" id="grc-tasks">
    <h2>Casella delle Attività</h2>
    <p>La <strong>Casella delle Attività</strong> mostra tutte le attività GRC assegnate a te su tutte le valutazioni. Le attività vengono create durante le valutazioni per delegare lavoro come raccolta di prove, rimediazione, revisioni o documentazione.</p>

    <h3>Come Usarla</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Valutazione &amp; Audit</span> &rarr; <span class="menu-label">Casella delle Attività</span>.</li>
        <li>Vedrai un elenco di attività assegnate a te. Ogni attività mostra: titolo, tipo (Richiesta di Prove, Rimediazione, Revisione, Documentazione, Implementazione), priorità, data di scadenza e stato.</li>
        <li>Fai clic su un'attività per visualizzarne i dettagli e aggiornarne lo stato.</li>
        <li>Contrassegna le attività come <span class="status-label">In Corso</span> quando inizi a lavorare e <span class="status-label">Completate</span> quando hai finito.</li>
    </ol>
</div>


<div class="doc-section" id="grc-dashboard">
    <h2>Dashboard GRC</h2>
    <p>La <strong>Dashboard GRC</strong> è il tuo centro di comando per la conformità &mdash; una panoramica a pagina singola dell'intera postura GRC.</p>

    <h3>Cosa Mostra la Dashboard</h3>
    <ul>
        <li><strong>Mappa di Calore della Conformità dei Framework</strong> &mdash; Percentuali di conformità codificate a colori per ogni framework</li>
        <li><strong>Progresso dell'Implementazione dei Controlli</strong> &mdash; Quanti controlli sono implementati rispetto a quelli pianificati</li>
        <li><strong>Freschezza delle Prove</strong> &mdash; Quante prove sono correnti, in scadenza o scadute</li>
        <li><strong>Riscontri Aperti</strong> &mdash; Conteggio e suddivisione per gravità dei riscontri di audit non risolti</li>
        <li><strong>Stato di Revisione delle Policy</strong> &mdash; Policy che devono essere riviste</li>
        <li><strong>Salute dei Monitor</strong> &mdash; Stato di superamento/fallimento dei monitor continui</li>
        <li><strong>Riepilogo del Registro dei Rischi</strong> &mdash; Rischi aperti per gravità</li>
    </ul>

    <h3>Come Accedere</h3>
    <p>Vai su <span class="menu-label">Modulo GRC</span> &rarr; <span class="menu-label">Conformità</span> &rarr; <span class="menu-label">Dashboard GRC</span>.</p>
</div>


<!-- ================================================================
     TPRM MODULE
     ================================================================ -->
<div class="doc-section" id="tprm-overview">
    <h2>Modulo TPRM: Cos'è la Gestione del Rischio di Terze Parti?</h2>
    <p>Ogni azienda si affida a fornitori esterni &mdash; provider cloud, società di gestione paghe, piattaforme di marketing, consulenti IT. Ogni fornitore può avere accesso ai tuoi dati o sistemi. Il <strong>TPRM</strong> ti aiuta a rispondere: "Quanto è rischioso ogni fornitore e stanno proteggendo i nostri dati?"</p>
    <ul>
        <li>Aggiungi e monitora tutti i tuoi fornitori in un unico posto</li>
        <li>Assegna un livello di rischio (Tier 1 = rischio più elevato, Tier 3 = più basso)</li>
        <li>Invia questionari di sicurezza (valutazioni) ai fornitori</li>
        <li>Assegna automaticamente punteggi ai fornitori usando servizi esterni di valutazione della sicurezza</li>
        <li>Esegui analisi quantitative del rischio (FAIR) per stimare le potenziali perdite finanziarie</li>
        <li>Monitora il rischio di quarte parti (i fornitori dei tuoi fornitori)</li>
        <li>Scopri applicazioni SaaS non gestite (Shadow SaaS)</li>
    </ul>
</div>

<div class="doc-section" id="tprm-add-vendor">
    <h2>Aggiungere un Nuovo Fornitore</h2>
    <ol class="steps">
        <li>Nella barra laterale sinistra, espandi <span class="menu-label">Modulo TPRM</span>, quindi espandi la sezione <span class="menu-label">Stakeholder</span>.</li>
        <li>Fai clic su <span class="menu-label">Nuova Richiesta</span>. Si apre il modulo di onboarding del fornitore.</li>
        <li>Compila i campi obbligatori:
            <ul>
                <li><span class="field-label">Nome Fornitore</span> &mdash; Il nome legale dell'azienda (es. "Acme Cloud Services")</li>
                <li><span class="field-label">Dominio Fornitore</span> &mdash; Il loro dominio web senza https:// (es. "acmecloud.com"). Usato dai motori di punteggio della sicurezza per scansionare il fornitore.</li>
            </ul>
        </li>
        <li>Compila i campi facoltativi consigliati:
            <ul>
                <li><span class="field-label">Tipo di Fornitore</span> &mdash; Tecnologia, Servizi Professionali, Servizi Finanziari, HR/Benefit, ecc.</li>
                <li><span class="field-label">Tier del Fornitore</span> &mdash; 1 (Critico), 2 (Importante) o 3 (Standard)</li>
                <li><span class="field-label">Nome del Contatto Principale</span>, <span class="field-label">Email</span>, <span class="field-label">Telefono</span></li>
                <li><span class="field-label">Conteggio Record PII</span> &mdash; Quanti record personali accede questo fornitore</li>
                <li><span class="field-label">Conteggio Record SPII</span> &mdash; Quanti record personali sensibili (CF, dati sanitari)</li>
            </ul>
        </li>
        <li>Fai clic su <span class="btn-label">Salva</span>. Il fornitore viene creato con stato <strong>Bozza</strong>.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Spiegazione dei Tier dei Fornitori:</strong><br>
        &bull; <strong>Tier 1 (Critico)</strong> &mdash; Fornitori con accesso a dati sensibili o sistemi critici. Richiedono una valutazione completa.<br>
        &bull; <strong>Tier 2 (Importante)</strong> &mdash; Fornitori con accesso moderato. Richiedono una valutazione standard.<br>
        &bull; <strong>Tier 3 (Standard)</strong> &mdash; Fornitori a basso rischio. Potrebbero richiedere solo una revisione di base.
    </div>
</div>

<div class="doc-section" id="tprm-lifecycle">
    <h2>Ciclo di Vita del Fornitore</h2>
    <p>I fornitori passano attraverso un ciclo di vita definito:</p>
    <p><span class="status-label">Bozza</span> &rarr; <span class="status-label">In attesa di Revisione</span> &rarr; <span class="status-label">In Revisione</span> &rarr; <span class="status-label">Approvato</span> (o <span class="status-label">Rifiutato</span>) &rarr; <span class="status-label">Attivo</span> &rarr; <span class="status-label">Revisione Annuale</span> &rarr; <span class="status-label">Dismesso</span></p>
    <p>Ogni fase attiva flussi di lavoro appropriati, notifiche e azioni richieste.</p>
</div>

<div class="doc-section" id="tprm-assessments">
    <h2>Valutazioni dei Fornitori</h2>
    <p>Le valutazioni dei fornitori sono questionari di sicurezza inviati ai fornitori per valutare la loro postura di sicurezza. Vai su <span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Valutazioni dei Fornitori</span> per gestirle.</p>
    <ol class="steps">
        <li>Apri la pagina di dettaglio di un fornitore.</li>
        <li>Fai clic su <span class="btn-label">Invia Valutazione</span>.</li>
        <li>Seleziona il template di valutazione appropriato per il tier del fornitore.</li>
        <li>Il fornitore riceve una email con un link per completare il questionario.</li>
        <li>Una volta inviato, esamina le risposte del fornitore e assegna loro un punteggio.</li>
    </ol>
</div>

<div class="doc-section" id="assessment-forms">
    <h2>Moduli di Valutazione: Scarica, Compila, Importa &amp; Compilazione automatica AI <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Non tutti i fornitori vogliono rispondere a un questionario nel browser. Dalla pagina di una singola valutazione (<span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Valutazioni dei Fornitori</span> &rarr; apri una valutazione) puoi consegnare al fornitore una copia offline, riprendere un file completato, o lasciare che un provider AI precompili le risposte dai certificati del fornitore stesso. I pulsanti si trovano in una riga vicino alla parte superiore della valutazione.</p>

    <h3>Scarica la valutazione come file compilabile</h3>
    <ul>
        <li><span class="btn-label">Scarica PDF</span> &mdash; un modulo PDF compilabile. Ogni domanda diventa un vero campo del modulo, così il fornitore può digitare e spuntare le caselle direttamente nel file.</li>
        <li><span class="btn-label">Scarica Excel</span> &mdash; una vera cartella di lavoro <code>.xlsx</code> che può essere completata in Excel, Google Sheets o LibreOffice. Le domande a scelta singola ottengono menu a discesa nelle celle, e le domande condizionali si disattivano automaticamente quando non si applicano.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Il PDF compilabile ora funziona in qualsiasi browser, non solo in Adobe.</strong> Le caselle di controllo hanno aspetti predefiniti integrati, così vengono mostrate e commutate in Chrome, Edge e altri visualizzatori PDF integrati (in precedenza funzionavano solo in Adobe Acrobat/Reader). Un campo con nome digitato etichettato <strong>&ldquo;SIGNATURE (TYPE FULL NAME)&rdquo;</strong> consente a chiunque di firmare in qualsiasi visualizzatore; i campi di firma digitale e data di firma esclusivi di Adobe rimangono nascosti tranne che in Acrobat/Reader, che possono effettivamente usarli.
    </div>

    <h3>Importa una valutazione completata (PDF, Excel o CSV)</h3>
    <p>Quando il fornitore rimanda indietro il file completato, fai clic su <span class="btn-label">Importa Valutazione Completata</span> e caricalo. La piattaforma rileva automaticamente il formato &mdash; un <strong>PDF</strong>, <strong>Excel (.xlsx)</strong> o <strong>CSV</strong> completato &mdash; e unisce le risposte alle risposte esistenti della valutazione.</p>
    <div class="callout callout-warning">
        <strong>Il Riferimento del file deve corrispondere.</strong> Ogni file scaricato contiene un <strong>Riferimento</strong> nascosto (l'ID della valutazione). Se il Riferimento è mancante o appartiene a una valutazione diversa, l'importazione viene rifiutata e nulla viene scritto &mdash; così le risposte non possono mai finire sulla valutazione sbagliata.
    </div>

    <h3>Hai invece un certificato? <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>A un fornitore che completa una valutazione può essere offerta una scorciatoia: se possiede una certificazione pertinente, può caricarla invece di rispondere a ogni domanda. Il messaggio <strong>&ldquo;Hai un Certificato?&rdquo;</strong> ora mostra qualsiasi <strong>Istruzioni per il Caricamento del Certificato</strong> l'autore del template abbia scritto, quindi non è più limitato a ISO 27001 &mdash; un template può invitare un SOC 2 Type 2, un ISO 27001 o qualsiasi altro certificato. (Gli autori dei template impostano questo testo nel Generatore di Template; vedi <a href="#admin-templates">Generatore di Template di Valutazione</a>.)</p>

    <h3>Compilazione automatica AI dalle Certificazioni <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Se il tuo amministratore ha configurato un <a href="#admin-ai">provider AI</a>, un revisore autorizzato può lasciare che l'AI legga i documenti di certificazione caricati dal fornitore e precompili il questionario. Fai clic su <span class="btn-label">&#9889; Compila automaticamente dalle Certificazioni</span> nella pagina della valutazione.</p>
    <figure class="doc-figure">
        <img src="app/docs/assessment-ai-autofill.png" alt="The Auto-Fill from Certifications button on a vendor assessment" loading="lazy">
        <figcaption><strong>Compilazione automatica dalle Certificazioni.</strong> Con un provider AI configurato, il pulsante appare accanto a <strong>Scarica PDF</strong>, <strong>Scarica Excel</strong> e <strong>Importa Valutazione Completata</strong>. Legge i documenti di certificazione correnti del fornitore e compila le domande a cui quei documenti rispondono.</figcaption>
    </figure>
    <p>Quando fai clic su di esso ti viene ricordato: <em>&ldquo;Questo analizzerà i documenti di certificazione del fornitore e precompilerà le domande senza risposta. Le risposte esistenti non verranno modificate.&rdquo;</em> L'AI lavora quindi attraverso i certificati del fornitore e riporta, ad esempio, <em>&ldquo;Compilate 12 di 30 domande senza risposta.&rdquo;</em> Alcune cose da sapere:</p>
    <ul>
        <li><strong>Vengono usati solo i certificati correnti.</strong> Legge i documenti caricati dal fornitore il cui tipo è <em>Certificazione</em> e che sono <strong>attivi e non scaduti</strong> (documenti PDF, CSV ed Excel; gli ultimi più recenti). Un certificato scaduto o sostituito viene ignorato.</li>
        <li><strong>Compila solo gli spazi vuoti.</strong> Le domande a cui hai già risposto vengono lasciate intatte, e non sovrascrive mai una risposta esistente.</li>
        <li><strong>Risponde solo in base a ciò che i documenti dicono effettivamente.</strong> All'AI viene indicato di non indovinare; qualsiasi cosa che non possa supportare con sicurezza dai documenti viene lasciata senza risposta perché una persona la completi.</li>
        <li><strong>Rimani tu al controllo.</strong> Le risposte compilate vengono salvate e la pagina si ricarica mostrandole, così puoi rivedere e modificare qualsiasi risposta prima che la valutazione venga inviata.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Chi può usarlo, e quando appare.</strong> Il pulsante è mostrato solo agli utenti <strong>Administrator</strong> e <strong>Cyber TPRM</strong>, solo quando un provider AI è abilitato, e solo quando il fornitore ha almeno un documento di certificazione corrente registrato. È nascosto sulle valutazioni completate.
    </div>
</div>

<div class="doc-section" id="tprm-action-plan">
    <h2>Piano d'Azione Fornitore <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>La scheda <strong>Piano d'Azione</strong> nella pagina di un fornitore consente al team cyber di pianificare lavoro di follow-up per quel fornitore &mdash; contattare il fornitore, inviare un'altra valutazione, forzare una revisione annuale &mdash; con una data di scadenza, responsabili e un insieme di note di stato. Un job giornaliero attiva ogni azione all'arrivo della sua data e la trasforma in un'attività da fare monitorata.</p>
    <p>Apri un fornitore da <span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Onboarding Fornitore</span> &rarr; <span class="menu-label">I Miei Fornitori</span>, quindi fai clic sulla scheda <strong>Piano d'Azione</strong>. La scheda è disponibile per gli utenti <strong>Administrator</strong> e <strong>Cyber TPRM</strong>.</p>

    <h3>Pianificare un'azione</h3>
    <ol class="steps">
        <li>Nella scheda <strong>Piano d'Azione</strong>, fai clic su <span class="btn-label">+ Crea Azione</span>.</li>
        <li>Scegli l'<span class="field-label">Azione</span>: <strong>Contatta Fornitore</strong>, <strong>Contatta Stakeholder</strong>, <strong>Invia Valutazione</strong> o <strong>Forza Revisione Annuale</strong>. (Se scegli <strong>Invia Valutazione</strong>, appare un selettore <span class="field-label">Valutazione del Fornitore</span> così puoi scegliere quale template inviare.)</li>
        <li>Imposta la <span class="field-label">Data di Scadenza</span> &mdash; il giorno in cui l'azione dovrebbe attivarsi.</li>
        <li>Sotto <span class="field-label">Assegna a (Cyber TPRM)</span>, seleziona uno o più responsabili Cyber TPRM. (Se non ce ne sono, l'azione ricade sullo stakeholder del fornitore.)</li>
        <li>Facoltativamente seleziona <span class="field-label">Invia un'email agli individui assegnati quando questa azione si attiva</span>, e usa <span class="field-label">Indirizzi email di notifica</span> per inviare invece a indirizzi specifici &mdash; separati da virgole. Lascialo vuoto per usare le email degli account degli assegnatari.</li>
        <li>Scrivi una <span class="field-label">Descrizione</span> (viene riportata nell'attività da fare che viene creata), poi fai clic su <span class="btn-label">Crea Azione</span>.</li>
    </ol>

    <h3>Cosa succede quando un'azione si attiva</h3>
    <p>Ogni azione si attiva una volta, alla o dopo la sua data di scadenza. L'attivazione crea un <strong>To-Do Cyber</strong> collegato che rimanda a questa scheda Piano d'Azione, esegue l'azione (per <strong>Invia Valutazione</strong> invia via email al fornitore il questionario; per <strong>Forza Revisione Annuale</strong> contrassegna la revisione annuale come dovuta) e &mdash; se lo hai abilitato &mdash; invia via email ai responsabili o agli indirizzi che hai elencato.</p>

    <h3>Stati delle azioni</h3>
    <p>Un'azione passa attraverso questi stati:</p>
    <p><span class="status-label">In attesa</span> &rarr; <span class="status-label">In Corso</span> (impostato automaticamente quando si attiva) &rarr; <span class="status-label">Completata</span>, oppure <span class="status-label">Problema</span> se qualcosa è andato storto durante l'attivazione, o <span class="status-label">Annullata</span> se la annulli prima che si attivi. Puoi cambiare lo stato tu stesso in qualsiasi momento; il job giornaliero non sovrascrive mai uno stato che hai impostato.</p>

    <h3>Note di stato</h3>
    <p>Apri un'azione per aggiungere <strong>Note di Stato</strong> datate man mano che il lavoro procede. Digita una nota e fai clic su <span class="btn-label">Aggiungi Nota</span>. Puoi modificare o eliminare le tue note; gli amministratori possono modificare o eliminare quelle di chiunque. Ogni creazione, modifica ed eliminazione viene registrata nel log di audit.</p>

    <div class="callout callout-info">
        <strong>Il job &ldquo;Vendor Remediation Schedule&rdquo;.</strong> Il job giornaliero che attiva le azioni dovute si chiama <strong>Vendor Remediation Schedule</strong> e viene eseguito ogni giorno alle <strong>7:00</strong> per impostazione predefinita. Gli amministratori possono abilitarlo, disabilitarlo o ripianificarlo nella pagina <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Scheduler</span>. Se è stato disattivato, recupera il ritardo la volta successiva che viene eseguito, attivando tutto ciò che è diventato dovuto nel frattempo.
    </div>
</div>

<div class="doc-section" id="tprm-srs">
    <h2>Security Risk Scorecard (SRS)</h2>
    <p>L'SRS fornisce un punteggio di sicurezza esterno automatizzato per ogni fornitore basato sulla configurazione DNS, SSL/TLS, sicurezza email (SPF, DKIM, DMARC), porte aperte e altri indicatori tecnici.</p>
    <p>Vai su <span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Moduli</span> &rarr; <span class="menu-label">Security Risk Scorecard</span>.</p>
</div>

<div class="doc-section" id="tprm-fair">
    <h2>Analisi FAIR</h2>
    <p><strong>FAIR</strong> (Factor Analysis of Information Risk) è un modello di rischio quantitativo che stima la probabile perdita finanziaria derivante da un evento di sicurezza che coinvolge un fornitore.</p>
    <p>Vai su <span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Moduli</span> &rarr; <span class="menu-label">Analisi FAIR</span> per creare e visualizzare le analisi.</p>
</div>

<div class="doc-section" id="tprm-fourth-party">
    <h2>Rischio di Quarte Parti</h2>
    <p>Monitora i fornitori da cui dipendono <em>i tuoi fornitori</em>. Se il tuo provider cloud utilizza un subappaltatore per l'archiviazione dei dati, questo è un rischio di quarta parte. Dalla barra laterale puoi aprire <span class="menu-label">Rischio di Quarte Parti</span> (concentrazione tecnologica), <span class="menu-label">Ricerca CVE</span> e <span class="menu-label">Subprocessori</span>. È disponibile per gli amministratori e gli utenti Cyber TPRM; gli auditor possono visualizzare ma non agire.</p>

    <h3>Concentrazione dei subprocessori <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Apri <span class="menu-label">Subprocessori</span> per vedere la visualizzazione <strong>Concentrazione dei Subprocessori</strong>: ogni subprocessore che i tuoi fornitori hanno dichiarato, e quanti dei tuoi fornitori usano ciascuno. Un subprocessore condiviso tra più fornitori viene evidenziato &mdash; quella dipendenza condivisa è un rischio di concentrazione della catena di approvvigionamento. (I subprocessori vengono aggiunti a un fornitore dalla pagina di dettaglio di quel fornitore.)</p>

    <h3>Invia una valutazione a tutti coloro che usano un subprocessore <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Quando un subprocessore concentra il rischio, puoi indagare i fornitori che ne dipendono in un'unica azione:</p>
    <ol class="steps">
        <li>Nell'elenco Subprocessori, fai clic su <span class="btn-label">Invia Valutazione</span> nella riga di quel subprocessore.</li>
        <li>Nel selettore dei fornitori, scegli quali dei fornitori che usano quel subprocessore devono ricevere la valutazione (o <span class="field-label">Seleziona Tutti i Visibili</span>), poi continua.</li>
        <li>Scegli un <span class="field-label">Template di Valutazione</span> e una finestra <span class="field-label">Scade Tra</span> (14, 30, 60 o 90 giorni), poi fai clic su <span class="btn-label">Assegna Valutazione</span>.</li>
    </ol>
    <p>A ogni fornitore selezionato viene inviato via email il questionario (una richiesta di informazioni), e un promemoria viene monitorato in modo che i follow-up partano automaticamente. I fornitori senza email registrata vengono saltati, e qualsiasi invio fallito viene ritentato dal job dei promemoria. Lo stesso flusso <strong>Assegna Valutazione</strong> è disponibile dalle visualizzazioni di concentrazione tecnologica e CVE.</p>
</div>

<div class="doc-section" id="tprm-shadow-saas">
    <h2>Scoperta Shadow SaaS</h2>
    <p>Scopri le applicazioni SaaS utilizzate nella tua organizzazione che potrebbero non essere state formalmente approvate o valutate. Vai su <span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Moduli</span> &rarr; <span class="menu-label">Shadow SaaS</span>. Nella versione v2.6.2 questo elenco può essere compilato automaticamente dall'integrazione Shadow SaaS <a href="#shadow-saas-grip">Grip</a> o <a href="#shadow-saas-hero">Hero</a>, e le app non autorizzate possono essere bloccate in <a href="#zscaler">Zscaler</a>.</p>
</div>


<!-- ================================================================
     VENDOR ONBOARDING & PROCUREMENT (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="onboarding-workflow">
    <h2>Onboarding dei Fornitori &amp; Onboarding Procurement <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Una <strong>richiesta di onboarding fornitore</strong> è il modo in cui un nuovo fornitore entra nella piattaforma. Passa attraverso una serie di <strong>stati</strong> dalla prima bozza alla decisione finale. Prima che il team cyber esamini un fornitore, il fornitore deve essere prima <strong>inserito attraverso il tuo processo di procurement</strong> e avere un <strong>ID Fornitore (VID)</strong> valido. Questa sezione spiega perché e come funziona esattamente.</p>

    <h3>Il percorso di onboarding (stati)</h3>
    <table class="doc-table">
        <tr><th>Stato</th><th>Cosa significa</th></tr>
        <tr><td><span class="status-label">Bozza</span></td><td>La richiesta è in fase di compilazione. Non è ancora stata inviata per la revisione.</td></tr>
        <tr><td><span class="status-label">Inviata</span></td><td>La richiesta ha superato i controlli di invio ed è stata inviata al team cyber.</td></tr>
        <tr><td><span class="status-label">In Revisione</span></td><td>Il team cyber sta esaminando il fornitore.</td></tr>
        <tr><td><span class="status-label">Revisione AI</span></td><td>I servizi del fornitore usano AI e si trova nella fase dedicata di revisione AI (vedi <a href="#ai-review">Revisione AI</a>).</td></tr>
        <tr><td><span class="status-label">Valutazione</span></td><td>Il fornitore è in fase di prova o valutazione.</td></tr>
        <tr><td><span class="status-label">Approvato</span></td><td>Il fornitore è stato approvato e inserito.</td></tr>
        <tr><td><span class="status-label">Rifiutato</span></td><td>Il fornitore non è stato approvato.</td></tr>
        <tr><td><span class="status-label">Inattivo</span></td><td>Il fornitore non è più attivo.</td></tr>
    </table>

    <h3>Trovare le richieste del tuo fornitore</h3>
    <p>Vai su <span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Stakeholder</span> &rarr; <span class="menu-label">Onboarding Fornitore</span>. Vedrai un elenco ricercabile di fornitori con il loro stato, tier, punteggio di sicurezza (SRS) e azioni rapide (Visualizza, Modifica). Usa i filtri in cima (ad esempio <strong>Tutti</strong>, <strong>Approvati</strong>, <strong>In Revisione</strong>) per restringere l'elenco. Usa <span class="btn-label">+ Nuova Richiesta</span> per avviare un nuovo fornitore.</p>
    <figure class="doc-figure">
        <img src="app/docs/vendor-onboarding-list.png" alt="Vendor Onboarding Requests list" loading="lazy">
        <figcaption><strong>Elenco Onboarding Fornitore.</strong> Ricerca, filtri e azioni per fornitore. Il filtro <strong>In Revisione</strong> è una visualizzazione singola che combina sia i fornitori <em>In Revisione</em> che quelli in <em>Revisione AI</em>.</figcaption>
    </figure>

    <h3 id="procurement-onboarding">Le due cose di cui ogni fornitore ha bisogno prima della revisione</h3>
    <p>Apri un fornitore e guarda la scheda <strong>Informazioni Fornitore</strong>. Due campi controllano se il fornitore può essere inviato per la revisione cyber:</p>
    <ul>
        <li><strong>Onboarding Procurement</strong> &mdash; un campo Sì/No che risponde alla domanda <em>"Questo fornitore ha completato l'Onboarding Procurement?"</em> Deve essere impostato su <strong>Sì</strong>.</li>
        <li><strong>ID Fornitore (VID)</strong> &mdash; l'identificatore da 4&ndash;8 cifre assegnato al fornitore dal tuo sistema di procurement. Deve essere un numero valido di 4&ndash;8 cifre.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/vendor-info-card.png" alt="Vendor Information card showing Procurement Onboarding and Vendor ID fields" loading="lazy">
        <figcaption><strong>Scheda Informazioni Fornitore.</strong> L'<strong>ID Fornitore (VID)</strong> e il campo di onboarding procurement devono essere entrambi compilati prima che il fornitore possa essere inviato. <em>Nota:</em> nelle istanze aggiornate da una versione precedente questo campo potrebbe ancora leggere <strong>"VSU Onboarded"</strong>; nella v2.6.2 è etichettato <strong>"Procurement Onboarding"</strong> &mdash; è lo stesso campo.</figcaption>
    </figure>

    <h3>Invio di un fornitore per la revisione</h3>
    <ol class="steps">
        <li>Apri il fornitore dall'elenco <span class="menu-label">Onboarding Fornitore</span> (la richiesta deve essere in stato <strong>Bozza</strong>).</li>
        <li>Nella scheda <strong>Informazioni Fornitore</strong>, imposta <span class="field-label">Onboarding Procurement</span> su <strong>Sì</strong> e inserisci un <span class="field-label">ID Fornitore (VID)</span> valido (4&ndash;8 cifre). Salva le modifiche.</li>
        <li>Fai clic su <span class="btn-label">Invia per Revisione</span>. Ti verrà chiesto di confermare: <em>"Inviare questo fornitore per la revisione? Il fornitore deve avere un VID valido ed essere inserito presso VSU."</em></li>
        <li>Se entrambi i controlli sono superati, lo stato cambia in <strong>Inviato</strong> e il team cyber viene notificato.</li>
    </ol>
    <div class="callout callout-danger">
        <strong>Se l'invio è bloccato,</strong> vedrai uno di questi messaggi:
        <ul style="margin:8px 0 0;">
            <li>"Impossibile inviare: il fornitore deve essere inserito presso VSU prima dell'invio. Completa la valutazione di onboarding con i dettagli VSU." &rarr; imposta <strong>Onboarding Procurement</strong> su <strong>Sì</strong>.</li>
            <li>"Impossibile inviare: è richiesto un ID Fornitore (VID) valido (4-8 cifre). Completa la valutazione di onboarding con l'ID Fornitore VSU." &rarr; inserisci un <strong>ID Fornitore</strong> valido di 4&ndash;8 cifre.</li>
        </ul>
        Vedi <a href="#troubleshooting">Risoluzione dei Problemi</a> per capire perché esiste questa regola.
    </div>
</div>

<div class="doc-section" id="custom-onboarding">
    <h2>Campi di Onboarding Personalizzati &amp; la Scheda Dati Personalizzati <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>I campi standard del fornitore (nome, dominio, tier, VAT e così via) coprono la maggior parte delle esigenze, ma ogni organizzazione monitora qualcosa in più. Nella v2.6.2 un template di onboarding può definire <strong>campi personalizzati</strong> che non hanno una colonna fornitore standard. I loro valori vengono acquisiti per fornitore e mostrati nella scheda <strong>Dati Personalizzati</strong> del fornitore.</p>

    <h3>Dove risiedono i valori personalizzati: la scheda Dati Personalizzati</h3>
    <p>Apri un fornitore (<span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Onboarding Fornitore</span> &rarr; <span class="menu-label">I Miei Fornitori</span> &rarr; apri un fornitore). Se il template di onboarding del fornitore definisce campi personalizzati, appare una scheda <strong>Dati Personalizzati</strong> accanto alle altre schede del fornitore, con un conteggio di quanti valori personalizzati sono registrati. La scheda è di sola lettura finché non fai clic su <span class="btn-label">Modifica</span>; apporta le modifiche e fai clic su <span class="btn-label">Salva Dati Personalizzati</span>. I campi sono raggruppati per sezione del template. Gli utenti a cui è consentito vedere un campo ma non modificarlo lo vedono contrassegnato come <em>(solo visualizzazione)</em>.</p>

    <h3>Definire un campo personalizzato (amministratori)</h3>
    <p>Un campo personalizzato è semplicemente una domanda su un template di categoria <strong>Onboarding</strong> il cui <span class="field-label">Nome Campo</span> è uno che <em>non</em> è una colonna fornitore integrata. Ci sono due passaggi, entrambi nel Portale Admin:</p>
    <ol class="steps">
        <li><strong>Registra il nome del campo.</strong> Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Riferimento Campi</span>, fai clic su <span class="btn-label">+ Aggiungi Campo</span> e aggiungi il tuo campo personalizzato (lettere minuscole, numeri e trattini bassi; es. <code>data_residency_region</code>). Scegli un tipo di colonna (testo, numero, data, ecc.) e la categoria <span class="field-label">Onboarding</span>.</li>
        <li><strong>Aggiungi una domanda che vi mappa.</strong> In <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Generatore di Template</span>, apri il tuo template di onboarding, aggiungi una domanda e imposta il suo <span class="field-label">Nome Campo</span> sul campo che hai appena registrato. Vedi <a href="#admin-templates">Generatore di Template di Valutazione</a>.</li>
    </ol>

    <h3>Nuovi tipi di campo per risposte più ricche <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Oltre ai tipi esistenti testo, numero, data, menu a discesa e radio, le domande (personalizzate o standard) possono ora usare:</p>
    <table class="doc-table">
        <tr><th>Tipo</th><th>Cosa vede il fornitore</th></tr>
        <tr><td><strong>Caselle di controllo</strong></td><td>Un elenco a selezione multipla &mdash; spunta ogni opzione applicabile.</td></tr>
        <tr><td><strong>Gruppo di Pulsanti (Multi)</strong></td><td>La stessa selezione multipla, mostrata come una riga di pulsanti di attivazione.</td></tr>
        <tr><td><strong>Telefono</strong></td><td>Un numero di telefono con selettore prefisso internazionale &amp; bandiera (vedi <a href="#question-types">Tipi di Domanda Telefono &amp; VAT</a>).</td></tr>
        <tr><td><strong>Numero VAT</strong></td><td>Un numero di partita IVA UE con doppia immissione e validazione VIES live (vedi <a href="#question-types">Tipi di Domanda Telefono &amp; VAT</a>).</td></tr>
    </table>
    <p>Le controparti a selezione singola (<strong>Menu a discesa</strong>, <strong>Pulsanti Radio</strong>, <strong>Gruppo di Pulsanti</strong>) sono ancora disponibili. I tipi a selezione multipla richiedono un elenco di <span class="field-label">Opzioni</span> (una per riga).</p>

    <h3>Controllare chi può vedere e modificare un campo (gating basato sui ruoli) <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Nei template di onboarding, ogni sezione e domanda <strong>personalizzata</strong> porta due controlli di ruolo, così puoi tenere i campi sensibili lontani dalle persone che non dovrebbero vederli:</p>
    <ul>
        <li><span class="field-label">Visibile ai Ruoli</span> &mdash; quali ruoli possono <em>vedere</em> il campo.</li>
        <li><span class="field-label">Ruoli con Visibilità e Modifica</span> &mdash; quali ruoli possono <em>modificarlo</em>.</li>
    </ul>
    <div class="callout callout-info">
        <strong>I campi personalizzati sono privati per impostazione predefinita.</strong> A differenza di una domanda standard, un campo personalizzato è nascosto finché non concedi un ruolo. Finché non viene concesso un ruolo, solo i super amministratori possono vederlo o modificarlo. Un campo che un visualizzatore non è autorizzato a vedere viene escluso dalla scheda Dati Personalizzati, dalla pagina del fornitore, dall'esportazione CSV e dall'API per quella persona. (Questa impostazione predefinita di sola concessione si applica ai campi personalizzati; le domande di onboarding standard non sono mai limitate in questo modo.)
    </div>
    <p>Sia la concessione di una sezione sia la concessione di una domanda devono consentire una persona prima che veda quella domanda, così puoi nascondere un'intera sezione o solo singoli campi al suo interno.</p>

    <h3>Valori personalizzati nelle esportazioni e nell'API</h3>
    <ul>
        <li><strong>Esportazione CSV.</strong> Nell'elenco <span class="menu-label">Onboarding Fornitore</span>, <span class="btn-label">Esporta CSV</span> (amministratori e Cyber TPRM) aggiunge ora una colonna per ogni campo personalizzato, denominata <code>custom:&lt;field_name&gt;</code>, accanto alle colonne standard.</li>
        <li><strong>API REST.</strong> La risposta per singolo fornitore (<code>GET /vendors/{id}</code>) include un array <code>custom_onboarding_data</code>; ogni voce ha <code>field_name</code>, <code>label</code>, <code>value</code>, <code>type</code>, <code>section</code> e <code>template_name</code>.</li>
    </ul>
    <p>Entrambi leggono dallo stesso posto della scheda Dati Personalizzati e rispettano la stessa visibilità basata sui ruoli &mdash; un campo che il chiamante (o il proprietario della chiave API) non può vedere viene lasciato vuoto o omesso.</p>
</div>

<div class="doc-section" id="ai-review">
    <h2>Revisione AI per i Fornitori <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Alcuni fornitori offrono servizi che utilizzano l'intelligenza artificiale. Questi fornitori possono comportare rischi diversi, quindi la versione v2.6.2 aggiunge uno stato dedicato di <strong>Revisione AI</strong> per tracciarli separatamente durante il processo di revisione.</p>

    <h3>Come un fornitore entra in Revisione AI</h3>
    <p>Nella scheda <strong>Informazioni Fornitore</strong> c'è un campo <span class="field-label">I Servizi Utilizzano AI</span>. Quando questo è impostato su <strong>Sì</strong>, un revisore autorizzato (un utente <strong>Cyber TPRM</strong> o <strong>Administrator</strong>, durante la modifica del fornitore) vede un link <span class="btn-label">Forza Revisione AI</span> direttamente sotto quel campo.</p>
    <ol class="steps">
        <li>Apri il fornitore e conferma che <span class="field-label">I Servizi Utilizzano AI</span> sia impostato su <strong>Sì</strong>.</li>
        <li>Fai clic su <span class="btn-label">Forza Revisione AI</span>. Conferma il messaggio: <em>"Forzare questo fornitore in Revisione AI?"</em></li>
        <li>Lo stato del fornitore cambia in <strong>Revisione AI</strong>.</li>
    </ol>
    <div class="callout callout-info">
        <strong>Perché il link potrebbe non apparire?</strong> Il link <strong>Forza Revisione AI</strong> appare solo quando (1) hai il permesso di approvare, (2) sei in modalità di modifica, (3) <strong>I Servizi Utilizzano AI</strong> è <strong>Sì</strong>, e (4) il fornitore non è già in Revisione AI. Se <strong>I Servizi Utilizzano AI</strong> è "No", vedrai il messaggio <em>"La Revisione AI può essere forzata solo per i fornitori i cui servizi utilizzano AI."</em></p>
    </div>
    <p>Nella pagina <a href="#procurement-cyber-status">Stato Cyber Procurement</a> e nel filtro <strong>In Revisione</strong> dell'elenco fornitori, i fornitori in <strong>In Revisione</strong> e <strong>Revisione AI</strong> vengono mostrati insieme &mdash; quindi nulla in revisione è mai nascosto solo perché viene revisionato con AI.</p>
</div>

<div class="doc-section" id="procurement-cyber-status">
    <h2>Stato Cyber Procurement <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>La pagina <strong>Stato Cyber</strong> offre al <strong>team di procurement</strong> una visione semplice e sempre aggiornata di quali fornitori il team cyber sta esaminando e qual è l'ultima notizia su ciascuno &mdash; senza bisogno di accedere agli strumenti di sicurezza completi. Il team cyber pubblica aggiornamenti brevi e datati; il procurement li legge qui (e in una email settimanale).</p>
    <p>Aprila da <span class="menu-label">Modulo TPRM</span> &rarr; <span class="menu-label">Procurement</span> &rarr; <span class="menu-label">Stato Cyber</span>. È disponibile per gli utenti <strong>Procurement</strong>, <strong>Cyber TPRM</strong> e <strong>Administrator</strong>.</p>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status.png" alt="Procurement Cyber Status page" loading="lazy">
        <figcaption><strong>Procurement &rarr; Stato Cyber.</strong> Elenca ogni fornitore il cui stato è <em>In Revisione</em> o <em>Revisione AI</em>, con il numero di aggiornamenti e la data dell'ultimo aggiornamento. Quando nessun fornitore è in revisione, la tabella viene sostituita da un messaggio "Nessun fornitore in revisione".</figcaption>
    </figure>

    <h3>Lettura della cronologia degli aggiornamenti di un fornitore</h3>
    <ol class="steps">
        <li>Fai clic sul nome di un fornitore nella tabella <strong>Fornitori in Revisione</strong>.</li>
        <li>Si apre il pannello <strong>Cronologia Aggiornamenti Procurement</strong>, che mostra ogni aggiornamento dal più recente al più vecchio: data e ora, chi l'ha scritto, lo stato del fornitore in quel momento e la nota stessa.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status-updates.png" alt="Procurement Update History for a vendor in review" loading="lazy">
        <figcaption><strong>Cronologia aggiornamenti di un fornitore.</strong> Facendo clic sul nome di un fornitore si apre la sua <strong>Cronologia Aggiornamenti Procurement</strong>. Ogni voce mostra la data e l'ora, l'autore, un badge per lo stato del fornitore quando la nota è stata scritta e la nota del team cyber &mdash; così il procurement può vedere esattamente dove si trova ogni revisione. Le cronologie lunghe vengono paginate con il controllo <em>Mostra per pagina</em>.</figcaption>
    </figure>

    <h3>Per i revisori cyber: pubblicare un aggiornamento al procurement</h3>
    <p>Gli utenti Cyber TPRM e gli admin possono pubblicare un aggiornamento per uno o più fornitori contemporaneamente:</p>
    <ol class="steps">
        <li>Nella pagina <strong>Stato Cyber</strong>, seleziona la casella di controllo accanto a ogni fornitore che vuoi aggiornare.</li>
        <li>Fai clic su <span class="btn-label">Fornisci al Procurement un Aggiornamento</span>.</li>
        <li>Nella finestra <strong>Fornisci al Procurement un Aggiornamento</strong>, digita la tua nota nella casella <span class="field-label">Aggiornamento</span>.</li>
        <li>Facoltativamente usa <span class="field-label">Cambia stato</span> per far avanzare il/i fornitore/i (ad esempio verso <strong>Valutazione</strong>, <strong>Approvato</strong> o <strong>Rifiutato</strong>). Lascia su <em>Mantieni stato attuale</em> per aggiungere solo una nota.</li>
        <li>Fai clic su <span class="btn-label">Salva Aggiornamento</span>. L'aggiornamento viene registrato per ogni fornitore selezionato.</li>
    </ol>

    <h3>L'email digest settimanale del procurement</h3>
    <p>Per tenere informato il procurement senza che nessuno debba accedere, la piattaforma può inviare via email un <strong>digest settimanale</strong> che elenca ogni fornitore in revisione insieme al suo aggiornamento più recente. Per impostazione predefinita viene inviato <strong>ogni lunedì alle 7:00</strong>.</p>
    <ol class="steps">
        <li>Un amministratore va su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Impostazioni Email</span> e trova le opzioni di <strong>Digest Aggiornamento Procurement</strong>.</li>
        <li>Attiva il digest e inserisci uno o più indirizzi email dei destinatari (separati da virgole).</li>
        <li>Salva. Puoi anche inviarlo immediatamente con <span class="btn-label">Invia digest ora</span> per testarlo.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-scheduler.png" alt="Admin Scheduler showing the Procurement Update Digest job" loading="lazy">
        <figcaption><strong>Admin &rarr; Scheduler.</strong> Il job <strong>Digest Aggiornamento Procurement</strong> (in fondo all'elenco) viene eseguito settimanalmente. Lo Scheduler è dove gli admin abilitano, disabilitano e pianificano tutti i job automatizzati.</figcaption>
    </figure>
</div>

<!-- ================================================================
     INTEGRATIONS: GRIP + ZSCALER (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="shadow-saas-grip">
    <h2>Integrazione Grip Shadow SaaS <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>"Shadow SaaS" indica le app cloud che i dipendenti usano senza che siano mai state formalmente approvate. <strong>Grip Security</strong> è un servizio che scopre queste app. Nella v2.6.2 puoi connettere il tuo account Grip in modo che la piattaforma importi automaticamente le app trovate da Grip &mdash; insieme a quante persone le usano, un punteggio di rischio e avvisi di sicurezza &mdash; e le elenca nella tua pagina <a href="#tprm-shadow-saas">Shadow SaaS</a>.</p>
    <p>Viene configurato da un amministratore in <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> nella scheda <strong>Grip</strong>. Grip è uno dei due provider Shadow SaaS (l'altro è <a href="#shadow-saas-hero">Hero</a>); solo uno può essere abilitato alla volta.</p>

    <h3>Connessione di Grip (passo dopo passo)</h3>
    <ol class="steps">
        <li>In Grip, crea un <strong>token API</strong> e annota l'URL base del tuo tenant (termina con <code>/public/saas</code>, ad esempio <code>https://tenant.dep.grip.security/public/saas</code>).</li>
        <li>Nella piattaforma, vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> e trova la scheda <strong>Grip Security Connection</strong>.</li>
        <li>Seleziona <span class="field-label">Abilita integrazione Grip Security</span>.</li>
        <li>Incolla il tuo URL tenant in <span class="field-label">Server (URL Base Tenant)</span> e il tuo token in <span class="field-label">Token API</span>.</li>
        <li>Fai clic su <span class="btn-label">Salva Configurazione</span>, quindi fai clic su <span class="btn-label">Testa Connessione</span> per confermare. Un messaggio di successo appare come <em>"Connesso a Grip — campione ha restituito 1 record"</em>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grip-card.png" alt="Grip Security Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Grip Security Connection.</strong> Inserisci il tuo URL tenant e il token API, salva, poi testa.</figcaption>
    </figure>

    <h3>Mantenimento automatico aggiornato</h3>
    <p>Usa la scheda condivisa <strong>Reidratazione Programmata</strong> (sotto le schede del provider) per aggiornare i dati del provider abilitato in base a una pianificazione. Seleziona <span class="field-label">Abilita reidratazione programmata</span> e inserisci una <span class="field-label">Pianificazione (espressione cron)</span> &mdash; ad esempio <code>0 2 * * *</code> per ogni giorno alle 2:00; la scheda mostra un riepilogo in linguaggio semplice di quello che hai digitato. Il job viene installato automaticamente nello scheduler del sistema (nessun passo manuale sul server) e sopravvive ai riavvii. Puoi anche fare clic su <span class="btn-label">Esegui Ora</span> per aggiornare immediatamente. La stessa pianificazione serve qualunque provider (Grip o Hero) sia attualmente abilitato.</p>

    <h3>Cosa vedrai in seguito</h3>
    <p>Le app scoperte appaiono nella pagina <span class="menu-label">Shadow SaaS</span> come voci <strong>In attesa</strong> con un punteggio di rischio (mostrato su scala 1&ndash;5), categoria e numero di utenti. Da lì puoi <strong>Consentire</strong> un'app (che inizia il suo onboarding come fornitore), <strong>Negare</strong> (contrassegnarla come non autorizzata e facoltativamente bloccarla in Zscaler), o <strong>Ignorare</strong>.</p>
    <figure class="doc-figure">
        <img src="app/docs/shadow-saas.png" alt="Shadow SaaS list page" loading="lazy">
        <figcaption><strong>La pagina Shadow SaaS.</strong> App scoperte e importate con il loro rischio e le azioni. Le app inserite come fornitori vengono saltate nelle sincronizzazioni future, e tutto ciò che hai ignorato rimane ignorato.</figcaption>
    </figure>

    <h3>Origine dati Live vs. Locale (in cache) <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Nella scheda Grip Security Connection, <span class="field-label">Origine dati</span> controlla da dove leggono le pagine Grip:</p>
    <ul>
        <li><strong>Live</strong> &mdash; chiama l'API Grip per ogni pagina. Sempre aggiornata, ma più pesante sull'API.</li>
        <li><strong>Locale (Idratata/in cache)</strong> &mdash; serve dalla copia dei dati Grip conservata nel database della piattaforma. Più leggera sull'API. In modalità Locale ogni sincronizzazione <strong>aggiorna completamente</strong> quella copia; tra una sincronizzazione e l'altra le pagine servono dallo snapshot invece di chiamare Grip.</li>
    </ul>

    <h3>Osservare e controllare una sincronizzazione <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Mentre una sincronizzazione è in esecuzione, la scheda <strong>Ultima Sincronizzazione</strong> mostra un indicatore di avanzamento live &mdash; <em>&ldquo;Idratazione dei roster per app &mdash; NN% (D / T app)&rdquo;</em> &mdash; sopra un pulsante <span class="btn-label">Interrompi Sincronizzazione</span> che annulla l'esecuzione in modo cooperativo. Per cancellare completamente i dati Grip serviti localmente, usa <span class="btn-label">Cancella Dati</span> sulla stessa scheda: cancella le tabelle mirror di Grip, le righe Grip nell'elenco Shadow SaaS e la telemetria Grip registrata sui record dei fornitori (la scheda SaaS Data). La tua cronologia di sincronizzazione viene conservata, e la sincronizzazione successiva reidrata tutto da Grip.</p>

    <h3>Valutazione SecurityScorecard (SSC) <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Quando Grip è connesso, una colonna <strong>SSC</strong> mostra il voto in lettere <strong>SecurityScorecard</strong> (A&ndash;F) di ogni app o fornitore nell'elenco Shadow SaaS e nell'elenco SRS dei fornitori, e nella scheda SaaS Data del fornitore. Appare solo mentre Grip è abilitato.</p>

    <h3>La scheda &ldquo;SaaS Data&rdquo; del fornitore <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Quando un fornitore corrisponde a un'app scoperta da Grip, sulla pagina di quel fornitore appare una scheda <strong>SaaS Data</strong> di sola lettura, che mostra la telemetria Grip raccolta durante la sincronizzazione senza lasciare il fornitore: <strong>Prima Scoperta</strong>, <strong>Account Attivi</strong> (un link nell'elenco degli utenti interessati), <strong>Ultimo Utilizzo Noto</strong>, classificazione dell'app, il voto <strong>Security Scorecard</strong>, categoria, profondità AI, segnali di conformità e supporto SAML/MFA.</p>

    <h3>Avvisi di violazione Grip <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>Grip può anche inviare gli incidenti di sicurezza nella piattaforma. Seleziona <span class="field-label">Invia le informazioni sulle violazioni di Grip in Avvisi di Violazione / Cyber</span> nella scheda di connessione e gli avvisi Grip &ldquo;Security Incident Detected&rdquo; vengono scritti nel tuo elenco <a href="#breach-alerts">Avvisi di Violazione / Cyber</a> a ogni sincronizzazione. (Questo richiede anche che la funzionalità Avvisi di Violazione/Cyber sia abilitata in <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Impostazioni Email</span>.)</p>
    <div class="callout callout-info">
        <strong>I dati personali sono crittografati a riposo.</strong> Nomi, indirizzi email e altri dettagli personali nei dati Grip in cache sono crittografati nel database e decrittografati solo quando vengono mostrati nell'app o restituiti dall'API. Questo è automatico e non richiede alcuna configurazione.
    </div>
</div>

<div class="doc-section" id="shadow-saas-hero">
    <h2>Integrazione Hero Shadow SaaS <span class="new-badge">Novità in 2.6.2</span></h2>
    <p><strong>HERO Security</strong> è un provider alternativo di Shadow SaaS. Invece di Grip, puoi connettere un account HERO e la piattaforma importa i fornitori scoperti da HERO &mdash; con il loro stato, un punteggio di rischio, il contatto più attivo e un conteggio degli utenti &mdash; nello stesso elenco <a href="#tprm-shadow-saas">Shadow SaaS</a>. Grip e Hero si <strong>escludono a vicenda</strong>: abilitare Hero disabilita automaticamente Grip (e viceversa), quindi l'elenco è sempre alimentato esattamente da un provider.</p>
    <p>Viene configurato da un amministratore in <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> nella scheda <strong>Hero</strong>.</p>

    <h3>Connessione di Hero (passo dopo passo)</h3>
    <ol class="steps">
        <li>Nel pannello admin di HERO, crea un <strong>client API</strong> e copia il suo <strong>Client ID</strong> e il <strong>Client Secret</strong> (il segreto viene mostrato una sola volta).</li>
        <li>Nella piattaforma, vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> e apri la scheda <strong>Hero</strong> per trovare la scheda <strong>HERO Security Connection</strong>.</li>
        <li>Seleziona <span class="field-label">Abilita integrazione HERO Security</span> (questo disabilita Grip).</li>
        <li>Lascia <span class="field-label">Server (URL Base)</span> come <code>https://api.herosecurity.ai/stable</code> a meno che non ti venga detto diversamente, e incolla il tuo <span class="field-label">Client ID</span> e <span class="field-label">Client Secret</span>.</li>
        <li>Fai clic su <span class="btn-label">Salva Configurazione</span>, quindi <span class="btn-label">Testa Connessione</span>. Un messaggio di successo appare come <em>"Connesso a HERO — campione ha restituito 1 record"</em>.</li>
    </ol>

    <h3>Cosa vedrai in seguito</h3>
    <p>I fornitori HERO appaiono nella pagina <span class="menu-label">Shadow SaaS</span> allo stesso modo delle app Grip &mdash; come voci <strong>In attesa</strong> che puoi Consentire, Negare o Ignorare. Per ogni fornitore la piattaforma registra:</p>
    <ul>
        <li><strong>Punteggio di Rischio (1&ndash;5)</strong> &mdash; derivato dal problema di sicurezza aperto più grave che HERO ha per quel fornitore (critico&nbsp;=&nbsp;5 fino a basso&nbsp;=&nbsp;2; i fornitori senza problemi aperti rimangono senza punteggio). È la stessa scala 1&ndash;5 usata da Grip.</li>
        <li><strong>Relationship Manager</strong> &mdash; il contatto osservato più attivo del fornitore (l'utente con la maggiore attività email).</li>
        <li><strong>Numero di Utenti</strong> &mdash; quanti utenti sono stati osservati interagire con il fornitore.</li>
        <li><strong>Tipo di Rischio</strong> &mdash; un riepilogo dei segnali di coinvolgimento di HERO (autorizzazione, attività, coinvolgimento commerciale) e il conteggio dei problemi aperti.</li>
    </ul>
    <p>Alcune colonne che altre fonti forniscono (categoria dell'applicazione, supporto MFA, cronologia delle violazioni, volumi di traffico, condivisione di file) non fanno parte dell'API HERO, quindi rimangono vuote per le righe Hero.</p>

    <div class="callout callout-info">
        <strong>Attenzione ai tempi di sincronizzazione.</strong> HERO restituisce i suoi dati per fornitore e limita il ritmo delle richieste, quindi un aggiornamento completo di un tenant grande richiede diversi minuti in background. Il job programmato e "Esegui Ora" si regolano automaticamente per rimanere entro i limiti di HERO.
    </div>
</div>

<div class="doc-section" id="zscaler">
    <h2>Integrazione Blocco Zscaler <span class="new-badge">Novità in 2.6.2</span></h2>
    <p><strong>Zscaler</strong> è un servizio di sicurezza web che può bloccare l'accesso ai siti web. Con questa integrazione, quando <strong>Neghi</strong> un'app non autorizzata nella pagina Shadow SaaS, la piattaforma può aggiungere automaticamente il dominio web di quell'app a una lista di blocco nel tuo account Zscaler &mdash; così le persone non possono più raggiungerlo. Fare clic su <strong>Consenti</strong> in seguito rimuove il blocco.</p>
    <p>Viene configurato da un amministratore in <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span>, nella scheda <strong>Zscaler Connection</strong>.</p>

    <h3>Connessione di Zscaler (passo dopo passo)</h3>
    <ol class="steps">
        <li>In Zscaler (ZIdentity), crea un <strong>Client API</strong> e copia il suo <strong>Client ID</strong> e il <strong>Client Secret</strong>. Annota il tuo <strong>dominio personalizzato</strong> (la parte prima di <code>.zslogin.net</code>).</li>
        <li>In ZIA, crea (o scegli) una <strong>categoria URL personalizzata</strong> a cui verranno aggiunti i domini bloccati, e annota il suo nome esatto.</li>
        <li>Nella scheda <strong>Zscaler Connection</strong> della piattaforma, seleziona <span class="field-label">Abilita blocco per categoria URL Zscaler su Nega</span>.</li>
        <li>Compila <span class="field-label">URL API</span> (predefinito <code>https://api.zsapi.net</code>), <span class="field-label">Dominio Personalizzato ZIdentity</span>, <span class="field-label">Client ID</span>, <span class="field-label">Client Secret</span> e il nome della <span class="field-label">Categoria URL</span>.</li>
        <li>Fai clic su <span class="btn-label">Salva Configurazione</span>, quindi <span class="btn-label">Testa Connessione</span> per confermare che le credenziali funzionino.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/zscaler-card.png" alt="Zscaler Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Zscaler Connection.</strong> Quando abilitato, il pulsante <strong>Nega</strong> su un'app Shadow SaaS aggiunge il suo dominio alla Categoria URL che hai indicato qui. La categoria deve già esistere in Zscaler.</figcaption>
    </figure>
    <div class="callout callout-info">
        <strong>Se il blocco è disattivato,</strong> negare un'app la contrassegna solo come non autorizzata nella piattaforma; nulla viene inviato a Zscaler. Vedrai <em>"Contrassegnata come non autorizzata. L'integrazione Zscaler non è abilitata; il dominio non è stato aggiunto alla Categoria URL."</em>
    </div>
</div>


<div class="doc-section" id="breach-alerts">
    <h2>Avvisi di Violazione / Cyber <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>La pagina <strong>Avvisi di Violazione / Cyber</strong> raccoglie in un unico posto i segnali di violazione e threat-intelligence per la tua catena di approvvigionamento dei fornitori. Aprila dalla barra laterale sotto <span class="menu-label">Avvisi di Violazione / Cyber</span> &rarr; <span class="menu-label">Avvisi di Violazione</span>; un badge rosso mostra il numero di nuovi avvisi.</p>

    <h3>Da dove provengono gli avvisi</h3>
    <p>Gli avvisi includono le violazioni rilevate dagli scanner AI di Violazione &amp; OSINT (vedi <a href="#admin-ai">Integrazione AI</a>) e, quando abilitati, gli incidenti di sicurezza da <a href="#shadow-saas-grip">Grip</a>. Ogni avviso mostra l'entità interessata, gli utenti potenzialmente colpiti, la tecnologia e quando è stato rilevato. Un incidente su un'app SaaS che <strong>non</strong> hai inserito come fornitore viene contrassegnato come <strong>&ldquo;Shadow SaaS&rdquo;</strong> con il numero di utenti potenzialmente colpiti; se quell'app viene inserita in seguito, gli incidenti futuri si collegano invece al fornitore.</p>

    <h3>Chi è stato colpito</h3>
    <p>Per un incidente proveniente da Grip, il conteggio degli utenti colpiti rimanda a un elenco di <strong>utenti interessati</strong> per quell'app. L'elenco è paginato e filtrabile (ad esempio per metodo di autenticazione), e ha una casella <strong>Cerca per nome o email</strong> per trovare una persona specifica. Poiché il roster è memorizzato crittografato, la ricerca viene eseguita sui dati decrittografati nell'applicazione, quindi funziona allo stesso modo dell'ordinamento e della paginazione.</p>

    <h3>Elaborare gli avvisi in blocco</h3>
    <p>Gli amministratori e gli utenti Cyber TPRM ottengono una barra degli strumenti a selezione multipla nell'elenco. Seleziona gli avvisi che vuoi (o usa <strong>Seleziona tutti</strong>) e applica un'azione a tutti contemporaneamente:</p>
    <ul>
        <li><span class="btn-label">Conferma</span> &mdash; contrassegna gli avvisi come visti.</li>
        <li><span class="btn-label">Falso Positivo</span> &mdash; contrassegnali come non un problema reale.</li>
        <li><span class="btn-label">Elimina</span> &mdash; rimuovili. <strong>Solo amministratori</strong>, e confermato prima dell'esecuzione.</li>
    </ul>
</div>


<!-- ================================================================
     ADMIN PORTAL
     ================================================================ -->
<div class="doc-section" id="admin-general">
    <h2>Portale Admin: Impostazioni Generali</h2>
    <p>Il Portale Admin è accessibile tramite <span class="menu-label">Amministrazione</span> nella barra laterale (solo utenti admin) o il link <span class="btn-label">Admin</span> nella barra superiore.</p>
    <p>Le Impostazioni Generali includono: nome dell'applicazione, nome dell'azienda, email di supporto e opzioni di configurazione a livello di sistema.</p>
</div>

<div class="doc-section" id="admin-branding">
    <h2>Branding &amp; Tema</h2>
    <p>Personalizza l'aspetto della piattaforma: carica il logo della tua azienda, imposta i colori della barra laterale, i colori dell'intestazione, i colori dei pulsanti e la larghezza di navigazione. Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Branding</span>.</p>
</div>

<div class="doc-section" id="admin-users">
    <h2>Gestione Utenti</h2>
    <p>Gestisci gli account utente e le assegnazioni ai gruppi. Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Utenti</span>.</p>

    <h3>Assegnazione degli Utenti ai Gruppi ACL</h3>
    <ol class="steps">
        <li>Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Utenti</span>.</li>
        <li>Trova l'utente nell'elenco.</li>
        <li>Fai clic sul pulsante <span class="btn-label">Gruppi</span> accanto al nome dell'utente.</li>
        <li>Apparirà una finestra modale che mostra tutti i gruppi disponibili con caselle di controllo. Seleziona i gruppi che vuoi assegnare (es. <strong>Cyber GRC</strong>, <strong>Administrator</strong>).</li>
        <li>Fai clic su <span class="btn-label">Salva Modifiche</span>.</li>
    </ol>
    <p>Oltre ad assegnare i gruppi predefiniti, i super amministratori possono costruire i propri gruppi con un set di permessi personalizzato &mdash; vedi <a href="#admin-acl-groups">Gruppi ACL &amp; Controllo Accessi Personalizzato</a>.</p>
</div>

<div class="doc-section" id="admin-acl-groups">
    <h2>Gruppi ACL &amp; Controllo Accessi Personalizzato <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>La piattaforma include sette gruppi predefiniti (administrator, cyber_tprm, procurement, stakeholder, auditor, cyber_grc, grc_contributors). Nella v2.6.2, i <strong>super amministratori</strong> possono anche creare i propri gruppi e regolare esattamente cosa può fare ciascuno. Apri <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Controllo Accessi</span> &rarr; <span class="menu-label">Gruppi ACL</span>. Qualsiasi amministratore può visualizzare questa pagina; solo i super amministratori vedono i controlli di creazione, modifica e permessi.</p>

    <h3>I gruppi predefiniti sono protetti</h3>
    <p>I sette gruppi predefiniti sono contrassegnati come <strong>Sistema</strong>. Non possono essere eliminati o rinominati, e i loro permessi sono di sola lettura &mdash; puoi aprire <span class="btn-label">Visualizza Permessi</span> per vedere esattamente cosa concedono, ma non modificarli. Questo mantiene stabili le impostazioni predefinite su cui tutti fanno affidamento.</p>

    <h3>Creare un gruppo personalizzato</h3>
    <ol class="steps">
        <li>Fai clic su <span class="btn-label">+ Crea Gruppo</span>.</li>
        <li>Inserisci un <span class="field-label">Nome Gruppo (macchina)</span> (lettere minuscole, numeri, trattini bassi &mdash; è fisso una volta creato), un <span class="field-label">Nome Visualizzato</span> descrittivo e una <span class="field-label">Descrizione</span>.</li>
        <li>Facoltativamente usa <span class="field-label">Copia permessi da</span> per <strong>clonare</strong> un gruppo esistente (incluso un gruppo di Sistema) come punto di partenza &mdash; poi perfezionalo. Lascialo su <em>&mdash; Inizia senza permessi &mdash;</em> per costruire da zero.</li>
        <li>Fai clic su <span class="btn-label">Crea Gruppo</span>.</li>
    </ol>

    <h3>Regolare la matrice dei permessi</h3>
    <p>Apri i <span class="btn-label">Permessi</span> di un gruppo personalizzato. I permessi sono raggruppati per modulo (Onboarding Fornitore, Analisi FAIR, Valutazioni, Security Rating (SRS), Revisioni Annuali, GRC e Altro). Ogni permesso è contrassegnato come <strong>Lettura</strong> o <strong>Lettura/Scrittura</strong>, e ogni modulo ha tre preimpostazioni con un clic:</p>
    <ul>
        <li><span class="btn-label">Lettura</span> &mdash; concede solo i permessi di visualizzazione/elenco/esportazione per quel modulo.</li>
        <li><span class="btn-label">Lettura &amp; Scrittura</span> &mdash; concede tutto (visualizzazione <em>e</em> modifica).</li>
        <li><span class="btn-label">Nessuno</span> &mdash; cancella il modulo.</li>
    </ul>
    <p>Fai clic su <span class="btn-label">Salva Permessi</span> al termine. Concedere <strong>Lettura</strong> non implica mai l'accesso in scrittura &mdash; la capacità di modificare qualcosa è sempre una concessione separata ed esplicita. Tutte le modifiche ai gruppi vengono registrate nel log di audit.</p>
</div>

<div class="doc-section" id="admin-templates">
    <h2>Generatore di Template di Valutazione <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Il <strong>Generatore di Template</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Generatore di Template</span>) è dove gli amministratori e gli utenti Cyber TPRM progettano i questionari di valutazione e onboarding. Usa <span class="btn-label">+ Aggiungi Sezione</span> e <span class="btn-label">+ Aggiungi Domanda</span> per costruire un template. Vale la pena evidenziare alcune aggiunte della v2.6.2.</p>

    <h3>Tipi di domanda e mappatura dei campi</h3>
    <p>Il <span class="field-label">Tipo di Domanda</span> di una domanda ora include <strong>Telefono</strong>, <strong>Numero VAT</strong>, <strong>Caselle di controllo</strong> e <strong>Gruppo di Pulsanti (Multi)</strong> oltre ai familiari tipi testo, menu a discesa e radio (vedi <a href="#custom-onboarding">Campi di Onboarding Personalizzati</a> per cosa acquisisce ciascuno). Il <span class="field-label">Nome Campo</span> di una domanda mappa la sua risposta a un campo fornitore; scegli un campo integrato o uno personalizzato che hai registrato in <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Riferimento Campi</span>.</p>

    <h3>Istruzioni per il Caricamento del Certificato</h3>
    <p>In un template puoi compilare le <span class="field-label">Istruzioni per il Caricamento del Certificato</span> &mdash; il testo mostrato a un fornitore nel messaggio <em>&ldquo;Hai un Certificato?&rdquo;</em>. Questo consente a un template di invitare qualsiasi certificato (SOC 2 Type 2, ISO 27001 e così via), non solo ISO 27001. Se lo lasci vuoto, viene mostrato un messaggio generico.</p>

    <h3>Visibilità basata sui ruoli nei template di onboarding</h3>
    <p>Per i template di <strong>onboarding</strong>, le sezioni e le domande personalizzate portano i controlli <span class="field-label">Visibile ai Ruoli</span> e <span class="field-label">Ruoli con Visibilità e Modifica</span>, così decidi chi può vedere e modificare ogni campo personalizzato. Vedi <a href="#custom-onboarding">Campi di Onboarding Personalizzati &amp; la Scheda Dati Personalizzati</a>.</p>

    <h3>I template disattivati sono nascosti per impostazione predefinita</h3>
    <p>L'elenco dei template mostra solo i template <strong>attivi</strong>. Se alcuni sono stati disattivati, un pulsante <span class="btn-label">Mostra Disattivati (N)</span> li rivela (e commuta su <span class="btn-label">Nascondi Disattivati (N)</span>), mantenendo l'elenco di un tenant di lunga durata focalizzato sui template effettivamente in uso senza perdere l'accesso a quelli ritirati.</p>
</div>

<div class="doc-section" id="admin-email">
    <h2>Configurazione Email</h2>
    <p>Configura le impostazioni SMTP per l'invio di notifiche email. Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email</span>. Le impostazioni includono host SMTP, porta, nome utente, password, metodo di crittografia (TLS/SSL) e indirizzo mittente.</p>
</div>

<div class="doc-section" id="admin-saml">
    <h2>SAML / SSO</h2>
    <p>Configura il Single Sign-On usando SAML 2.0. Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span>. Questo consente agli utenti di accedere usando il provider di identità della tua organizzazione (Okta, Azure AD, ecc.).</p>
    <ol class="steps">
        <li>Seleziona <span class="field-label">Abilita SAML 2.0</span>.</li>
        <li>Compila ogni campo obbligatorio del Provider di Identità (<span class="field-label">IdP Entity ID</span>, <span class="field-label">IdP Single Sign-On URL</span>, <span class="field-label">IdP X.509 Certificate</span>) e del Provider di Servizi (<span class="field-label">SP Entity ID</span>, <span class="field-label">SP ACS URL</span>). L'SSO si attiva solo quando <strong>tutti</strong> questi campi sono compilati &mdash; un modulo parzialmente compilato rimane disabilitato.</li>
        <li>Fai clic su <span class="btn-label">Salva</span>.</li>
    </ol>

    <h3>Utilizzo contemporaneo del login locale e SSO</h3>
    <p>Per impostazione predefinita, abilitare SSO <strong>non</strong> disattiva il modulo locale di nome utente/password &mdash; la pagina di accesso mostra un pulsante <strong>Accedi con SSO</strong> <em>e</em> un'opzione di accesso locale, così entrambi funzionano fianco a fianco. Il comportamento è controllato da un singolo interruttore di login locale:</p>
    <table>
        <tr><th>Modalità</th><th>Cosa vedono gli utenti</th></tr>
        <tr><td><strong>Login locale abilitato</strong> (predefinito)</td><td>Pulsante SSO <em>e</em> il modulo nome utente/password. Usalo per eseguire entrambi contemporaneamente.</td></tr>
        <tr><td><strong>Login locale disabilitato</strong> (solo SSO)</td><td>SSO è l'unico percorso per gli utenti normali. L'account <strong>break-glass admin</strong> designato può ancora accedere localmente, così un provider di identità non funzionante non può mai bloccare tutti.</td></tr>
    </table>
    <p>Se SAML non è effettivamente configurato, l'interruttore viene ignorato e il login locale rimane sempre disponibile (rete di sicurezza anti-blocco).</p>

    <h3>Break-glass: consentire SAML e login locale insieme (file di configurazione) <span class="new-badge">Novità in 2.6.2</span></h3>
    <p>L'interruttore di login locale può essere impostato in due modi. L'impostazione del file di configurazione, quando presente, <strong>ha la precedenza sul valore del database</strong> &mdash; un controllo break-glass che non necessita di accesso al database, così puoi sempre ripristinare il login locale anche se SSO non funziona correttamente.</p>
    <table>
        <tr><th>Dove</th><th>Come</th></tr>
        <tr><td>Pagina Admin &rarr; SAML</td><td>Nella scheda <strong>Impostazioni di Connessione</strong>, seleziona o deseleziona <span class="field-label">Consenti login locale con nome utente/password (in aggiunta a SSO)</span> e fai clic su <span class="btn-label">Salva Configurazione SAML</span>. Questo scrive l'impostazione <code>local_login_enabled</code> (abilitata per impostazione predefinita) &mdash; nessuna SQL necessaria.</td></tr>
        <tr><td>File di configurazione (ha la precedenza se impostato)</td><td>In <code>config/config.php</code>, sotto il blocco <code>auth</code>, imposta <code>'local_login_enabled' =&gt; true</code> per mantenere il login locale sempre disponibile (sia locale + SSO), o <code>false</code> per solo SSO. Questo <strong>sostituisce</strong> l'interruttore sopra; mentre è impostato, la casella di controllo nella pagina SAML viene mostrata in sola lettura. Rimuovi la riga per gestirla nuovamente dall'interfaccia utente. Riavvia il container dopo aver modificato <code>config.php</code>.</td></tr>
    </table>
    <p>Per eseguire <strong>sia SAML che il login locale</strong> senza modifiche al database, configura SAML come sopra e aggiungi questo al blocco <code>auth</code> di <code>config/config.php</code>, poi riavvia il container:</p>
    <pre><code>'auth' =&gt; [
    // ...
    // Break-glass: true = login locale sempre disponibile insieme a SSO;
    // false = solo SSO (l'admin break-glass può ancora accedere localmente).
    'local_login_enabled' =&gt; true,
],</code></pre>
</div>

<div class="doc-section" id="admin-ai">
    <h2>Integrazione AI</h2>
    <p>Abilita le funzionalità basate sull'AI tra cui il perfezionamento delle note di valutazione, i suggerimenti sui controlli, i commenti sui fornitori, l'analisi del rischio FAIR assistita dall'AI e l'assistenza linguistica nei report. Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Piattaforma AI</span> per scegliere un provider e inserire la sua chiave API. È attiva solo una piattaforma alla volta.</p>
    <p>Piattaforme AI supportate:</p>
    <ul>
        <li><strong>Anthropic (Claude)</strong> <span class="new-badge">Novità in 2.6.2</span> &mdash; si connette direttamente all'API nativa di Claude (es. <code>claude-opus-4-8</code>). Incolla la tua chiave API Anthropic; l'endpoint viene impostato di default sull'URL Messages standard. Supporta la <strong>ricerca web</strong> live, così gli Avvisi di Violazione e le scansioni OSINT sono basati su fonti correnti e citate.</li>
        <li><strong>OpenAI (ChatGPT)</strong> <span class="new-badge">Novità in 2.6.2</span> &mdash; si connette direttamente a OpenAI (es. <code>gpt-4o</code>). Incolla la tua chiave API OpenAI. Per le scansioni Violazione &amp; OSINT usa un modello con capacità di ricerca web (predefinito <code>gpt-4o-search-preview</code>) così quelle scansioni sono basate su fonti live.</li>
        <li><strong>OpenWebUI</strong> &mdash; token bearer JWT contro un endpoint compatibile con OpenAI.</li>
        <li><strong>LibreChat</strong> &mdash; autenticazione tramite chiave API, basata su agenti; l'agente gestisce il proprio modello e il campionamento.</li>
        <li><strong>Personalizzato</strong> &mdash; incolla un template di intestazioni + corpo in stile curl per qualsiasi altro endpoint compatibile con OpenAI (o orchestratore).</li>
    </ul>
    <p><strong>Scelta e caricamento di un modello:</strong> dopo aver inserito e <strong>salvato</strong> una chiave, fai clic su <span class="btn-label">Carica Modelli</span> nella scheda di quella piattaforma per recuperare il suo elenco di modelli disponibili (OpenWebUI / LibreChat / OpenAI). Per Anthropic, digita il nome del modello direttamente (es. <code>claude-opus-4-8</code>).</p>
    <p><strong>Ancoraggio degli Avvisi di Violazione:</strong> gli scanner Violazione &amp; OSINT hanno bisogno di un provider in grado di cercare sul web. <strong>Anthropic (Claude)</strong> e <strong>OpenAI (ChatGPT)</strong> si ancorano nativamente; OpenWebUI / LibreChat si ancorano solo se l'agente sottostante ha la navigazione; la piattaforma Personalizzata si ancora solo quando è configurato un URL di Ricerca Web.</p>
    <p>Il provider AI attivo alimenta anche la <strong>traduzione automatica delle domande di valutazione</strong> (vedi <a href="#language">Cambiare la Lingua</a>).</p>
</div>

<div class="doc-section" id="admin-updates">
    <h2>Aggiornamento della Piattaforma <span class="new-badge">Novità in 2.6.2</span></h2>
    <p>Gli amministratori possono verificare e applicare nuove versioni dall'interno della piattaforma. Vai su <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Versione</span>.</p>
    <ol class="steps">
        <li>La scheda <strong>Stato Attuale</strong> mostra la tua <span class="field-label">Versione Installata</span> e se ne è disponibile una più recente.</li>
        <li>Conferma che il <span class="field-label">Nome Host del Registry</span> sia corretto (il tuo image registry), poi fai clic su <span class="btn-label">Verifica Aggiornamenti</span>.</li>
        <li>Se è elencata una versione più recente, segui l'azione di <strong>Aggiornamento</strong> sullo schermo per applicarla.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-version.png?v=20260711" alt="Version Management page showing installed version 2.6.2" loading="lazy">
        <figcaption><strong>Admin &rarr; Versione.</strong> Qui la versione installata è <strong>v2.6.2</strong> e la piattaforma riporta che è aggiornata. Questo è anche dove confermi a quale versione si applica questa guida.</figcaption>
    </figure>
</div>


<!-- ================================================================
     FAQ (SEARCHABLE)
     ================================================================ -->
<div class="doc-section" id="faq">
    <h2>Domande Frequenti</h2>
    <p>Digita una parola chiave di seguito per filtrare immediatamente le domande &mdash; ad esempio <em>lingua</em>, <em>VID</em>, <em>onboarding</em>, <em>Grip</em> o <em>password</em>.</p>
    <div class="faq-search-wrap">
        <input type="text" id="faqSearch" class="faq-search" placeholder="Cerca nelle FAQ&hellip;" aria-label="Search frequently asked questions" autocomplete="off">
    </div>
    <p class="faq-count" id="faqCount"></p>
    <div id="faqList">

        <details class="faq-item"><summary>Gli utenti possono accedere contemporaneamente con SSO e una password locale?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>Sì. Abilitare SAML/SSO <strong>non</strong> disattiva il login locale per impostazione predefinita &mdash; la pagina di accesso mostra un pulsante <strong>Accedi con SSO</strong> e un'opzione locale di nome utente/password insieme. Lo controlli con la casella di controllo <strong>Consenti login locale con nome utente/password</strong> nella pagina <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span> (lasciala selezionata per eseguire entrambi; deselezionala per solo SSO, dove l'admin break-glass può ancora accedere localmente). Per un controllo break-glass senza database, la stessa impostazione può essere forzata in <code>config/config.php</code> tramite <code>'local_login_enabled' =&gt; true</code>, che sovrascrive la casella di controllo. Vedi <a href="#admin-saml">SAML / SSO</a>.</p></div></details>

        <details class="faq-item"><summary>L'SSO è mal configurato e nessuno riesce ad accedere. Come rientro?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>Usa il controllo break-glass: in <code>config/config.php</code>, sotto il blocco <code>auth</code>, imposta <code>'local_login_enabled' =&gt; true</code> e riavvia il container. Questo riabilita il modulo locale di nome utente/password indipendentemente dall'impostazione del database, così puoi accedere e correggere la configurazione SAML. Vedi <a href="#admin-saml">SAML / SSO</a>.</p></div></details>

        <details class="faq-item"><summary>Come cambio la lingua della piattaforma?<span class="faq-tag">Lingua</span></summary>
            <div class="faq-body"><p>Fai clic su <strong>Profilo</strong> (in alto a destra), apri la scheda <strong>Preferenza Lingua</strong>, scegli la tua lingua e fai clic su <strong>Aggiorna Lingua</strong>. Questo cambia solo il tuo schermo. Vedi <a href="#language">Cambiare la Lingua</a>.</p></div></details>

        <details class="faq-item"><summary>Quali lingue sono supportate?<span class="faq-tag">Lingua</span></summary>
            <div class="faq-body"><p>Inglese, Spagnolo, Italiano, Ucraino, Cinese (Semplificato), Hindi, Francese e Portoghese. Il tuo amministratore decide quali di queste appaiono nel tuo elenco; l'inglese è sempre disponibile.</p></div></details>

        <details class="faq-item"><summary>Ho cambiato la lingua ma alcuni testi sono ancora in inglese. Perché?<span class="faq-tag">Lingua</span></summary>
            <div class="faq-body"><p>Alcune cose diverse possono rimanere in inglese anche dopo aver cambiato lingua:</p>
            <ul>
                <li><strong>Testo dell'interfaccia non ancora tradotto.</strong> Menu, pulsanti ed etichette sono tradotti ovunque esista una traduzione per la tua lingua. Se una particolare stringa non è ancora stata tradotta nella tua lingua, ricade sull'inglese invece di mostrare uno spazio vuoto &mdash; quindi potresti vedere qualche etichetta occasionale in inglese.</li>
                <li><strong>Qualsiasi cosa sia stata digitata.</strong> I contenuti che tu o i tuoi fornitori immettete &mdash; nomi dei fornitori, note, nomi dei documenti caricati, risposte a testo libero &mdash; vengono mostrati esattamente come sono stati scritti, in qualunque lingua fosse.</li>
                <li><strong>Domande di valutazione senza un provider AI.</strong> Il testo delle <em>domande</em> delle valutazioni dei fornitori viene tradotto automaticamente solo quando il tuo amministratore ha configurato un provider AI; senza di esso, le domande rimangono nella lingua in cui sono state scritte. I valori delle risposte memorizzate rimangono sempre in inglese in modo che il punteggio rimanga coerente.</li>
                <li><strong>Le email e alcuni componenti di terze parti</strong> non sono controllati dalla tua impostazione della lingua.</li>
            </ul>
            <p>Se vedi un'etichetta dell'interfaccia che dovrebbe essere tradotta ma non lo è, comunicalo al tuo amministratore in modo che il testo mancante possa essere aggiunto.</p></div></details>

        <details class="faq-item"><summary>Perché non riesco a inviare il mio fornitore per la revisione?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>Un fornitore può essere inviato solo dopo aver completato l'<strong>Onboarding Procurement</strong> (impostato su <strong>Sì</strong>) e aver un <strong>ID Fornitore (VID)</strong> valido di 4&ndash;8 cifre. Apri il fornitore, compila entrambi nella scheda <strong>Informazioni Fornitore</strong>, salva, poi fai clic su <strong>Invia per Revisione</strong>. Vedi <a href="#onboarding-workflow">Onboarding Fornitore</a> e <a href="#troubleshooting">Risoluzione dei Problemi</a>.</p></div></details>

        <details class="faq-item"><summary>Cos'è un ID Fornitore (VID) e dove lo ottengo?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>Il VID è un numero di 4&ndash;8 cifre assegnato al fornitore dal tuo sistema di procurement quando il fornitore viene inserito. Collega il fornitore qui ai tuoi record di procurement e finanziari. Se non ne hai uno, il fornitore non ha ancora completato l'onboarding procurement.</p></div></details>

        <details class="faq-item"><summary>Il campo nel mio fornitore dice "VSU Onboarded", ma la guida dice "Procurement Onboarding". Qual è quello corretto?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>Sono lo stesso campo. È stato rinominato nel più chiaro <strong>"Procurement Onboarding"</strong> nella v2.6.2. Se il tuo schermo mostra ancora <strong>"VSU Onboarded"</strong>, la tua istanza non è ancora stata aggiornata all'ultima immagine v2.6.2 &mdash; il comportamento è identico.</p></div></details>

        <details class="faq-item"><summary>Cosa significa "Revisione AI" per un fornitore?<span class="faq-tag">Revisione AI</span></summary>
            <div class="faq-body"><p>È uno stato di revisione separato per i fornitori i cui servizi usano AI, così possono essere tracciati separatamente dalle revisioni ordinarie. Un utente Cyber TPRM o admin sposta un fornitore in esso con il link <strong>Forza Revisione AI</strong>. Vedi <a href="#ai-review">Revisione AI per i Fornitori</a>.</p></div></details>

        <details class="faq-item"><summary>Non vedo il link "Forza Revisione AI". Perché?<span class="faq-tag">Revisione AI</span></summary>
            <div class="faq-body"><p>Appare solo quando stai modificando il fornitore con permesso di approvazione, il campo <strong>I Servizi Utilizzano AI</strong> del fornitore è <strong>Sì</strong>, e il fornitore non è già in Revisione AI.</p></div></details>

        <details class="faq-item"><summary>Cos'è la pagina Stato Cyber Procurement?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>Una pagina in linguaggio semplice (<strong>TPRM &rarr; Procurement &rarr; Stato Cyber</strong>) dove il procurement può vedere quali fornitori il team cyber sta esaminando e leggere gli aggiornamenti datati che il team cyber pubblica. Vedi <a href="#procurement-cyber-status">Stato Cyber Procurement</a>.</p></div></details>

        <details class="faq-item"><summary>Come riceve il procurement le email di aggiornamento?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>Un amministratore attiva il <strong>Digest Aggiornamento Procurement</strong> in <strong>Admin &rarr; Impostazioni Email</strong> e aggiunge gli indirizzi dei destinatari. Viene inviato via email settimanalmente (per impostazione predefinita il lunedì alle 7:00) e può anche essere inviato su richiesta.</p></div></details>

        <details class="faq-item"><summary>Cos'è Grip e cosa fa qui?<span class="faq-tag">Integrazioni</span></summary>
            <div class="faq-body"><p>Grip Security scopre le app SaaS usate nella tua organizzazione. Quando connesso (<strong>Admin &rarr; Shadow SaaS</strong>), la piattaforma importa automaticamente quelle app, i conteggi degli utenti, i punteggi di rischio e gli avvisi nel tuo elenco Shadow SaaS. Vedi <a href="#shadow-saas-grip">Integrazione Grip Shadow SaaS</a>.</p></div></details>

        <details class="faq-item"><summary>Qual è la differenza tra le integrazioni Grip e Hero?<span class="faq-tag">Integrazioni</span></summary>
            <div class="faq-body"><p>Entrambe alimentano lo stesso elenco Shadow SaaS da un servizio di scoperta di terze parti &mdash; Grip Security o HERO Security &mdash; e condividono entrambi il blocco Zscaler e il job di Reidratazione Programmata. Si <strong>escludono a vicenda</strong>: abilitarne uno disabilita l'altro, così esegui qualunque provider utilizzi la tua organizzazione. Vedi <a href="#shadow-saas-hero">Integrazione Hero Shadow SaaS</a>.</p></div></details>

        <details class="faq-item"><summary>Il "Test Connessione" di Grip è fallito. Cosa devo controllare?<span class="faq-tag">Integrazioni</span></summary>
            <div class="faq-body"><p>Conferma che il <strong>Server (URL Base Tenant)</strong> termini con <code>/public/saas</code>, che il <strong>Token API</strong> sia aggiornato e che il tuo server possa raggiungere l'endpoint Grip. Un errore di token riporta <em>"Non autorizzato — token rifiutato"</em>; un errore di URL riporta <em>"Endpoint non trovato — controlla l'URL base"</em>.</p></div></details>

        <details class="faq-item"><summary>Cosa fa l'integrazione Zscaler?<span class="faq-tag">Integrazioni</span></summary>
            <div class="faq-body"><p>Quando <strong>Neghi</strong> un'app non autorizzata, la piattaforma può aggiungere il suo dominio web a una Categoria URL di blocco nel tuo account Zscaler in modo che le persone non possano raggiungerlo. Facendo clic su <strong>Consenti</strong> in seguito si rimuove il blocco. Vedi <a href="#zscaler">Integrazione Blocco Zscaler</a>.</p></div></details>

        <details class="faq-item"><summary>Qual è la differenza tra Consenti, Nega e Ignora su un'app Shadow SaaS?<span class="faq-tag">Integrazioni</span></summary>
            <div class="faq-body"><p><strong>Consenti</strong> inizia l'onboarding dell'app come fornitore; <strong>Nega</strong> la contrassegna come non autorizzata (e può bloccarla in Zscaler); <strong>Ignora</strong> la nasconde dall'elenco. Le app ignorate rimangono ignorate anche dopo le sincronizzazioni future.</p></div></details>

        <details class="faq-item"><summary>Chi può vedere il modulo GRC?<span class="faq-tag">Accesso</span></summary>
            <div class="faq-body"><p>Gli utenti nei gruppi <strong>Administrator</strong>, <strong>Cyber GRC</strong> o <strong>Auditor</strong>. Se non lo vedi, chiedi al tuo amministratore di aggiungerti a uno di questi gruppi. Vedi <a href="#roles">Ruoli Utente &amp; Permessi</a>.</p></div></details>

        <details class="faq-item"><summary>Come attivo l'autenticazione a due fattori (2FA)?<span class="faq-tag">Account</span></summary>
            <div class="faq-body"><p>Apri <strong>Profilo</strong> e usa la scheda <strong>Autenticazione a Due Fattori (TOTP)</strong> per abilitarla con un'app di autenticazione come Google Authenticator o Microsoft Authenticator.</p></div></details>

        <details class="faq-item"><summary>Posso salvare o stampare questa documentazione?<span class="faq-tag">Generale</span></summary>
            <div class="faq-body"><p>Sì. Fai clic su <strong>Scarica PDF</strong> in cima a questa pagina. Produce un documento formattato con una pagina di copertina, un indice dei contenuti e numeri di pagina.</p></div></details>

        <details class="faq-item"><summary>Come faccio a sapere quale versione sto usando?<span class="faq-tag">Generale</span></summary>
            <div class="faq-body"><p>Gli amministratori possono controllare <strong>Admin &rarr; Versione</strong>. Questa guida descrive la <strong>v2.6.2</strong>. Vedi <a href="#admin-updates">Aggiornamento della Piattaforma</a>.</p></div></details>

    </div>
    <p class="faq-no-results" id="faqNoResults">Nessuna domanda corrisponde alla tua ricerca. Prova una parola chiave diversa.</p>
</div>


<!-- ================================================================
     TROUBLESHOOTING
     ================================================================ -->
<div class="doc-section" id="troubleshooting">
    <h2>Risoluzione dei Problemi</h2>

    <h3>Perché è importante l'onboarding tramite procurement (la regola VID &amp; Onboarding Procurement)</h3>
    <p>Questo è il singolo motivo più comune che impedisce a un fornitore di procedere, quindi vale la pena capirlo. La piattaforma <strong>non permetterà a un fornitore di essere inviato per la revisione cyber</strong> finché due informazioni di procurement non sono registrate per il fornitore:</p>
    <ul>
        <li><strong>Onboarding Procurement = Sì</strong> &mdash; conferma che il fornitore è stato configurato e verificato attraverso il processo di procurement della tua organizzazione.</li>
        <li>Un <strong>ID Fornitore (VID)</strong> valido &mdash; il numero da 4&ndash;8 cifre che il procurement assegna al fornitore.</li>
    </ul>
    <p>Perché applicare questa regola? Perché il VID è la chiave condivisa che collega questo fornitore ai record di procurement, finanziari e contrattuali. Se il team cyber revisionasse e approvasse un fornitore che il procurement non aveva mai inserito, si finirebbe per avere fornitori duplicati o "fantasma", lavoro di sicurezza che non può essere ricondotto a un ordine di acquisto reale e report che non si conciliano. Richiedere prima l'onboarding procurement mantiene la revisione della sicurezza e il record di procurement che puntano allo stesso fornitore reale.</p>
    <div class="callout callout-warning">
        <strong>Come risolvere:</strong> Apri il fornitore e nella scheda <strong>Informazioni Fornitore</strong> imposta <strong>Onboarding Procurement</strong> su <strong>Sì</strong> e inserisci l'<strong>ID Fornitore (VID)</strong> di 4&ndash;8 cifre dal tuo sistema di procurement. Salva, poi fai di nuovo clic su <strong>Invia per Revisione</strong>. Se non hai ancora un VID, il fornitore non ha completato l'onboarding procurement &mdash; inizia da lì.
    </div>

    <h3>Problemi comuni e come risolverli</h3>
    <table class="doc-table">
        <tr><th>Sintomo</th><th>Causa probabile &amp; soluzione</th></tr>
        <tr><td>"Impossibile inviare: il fornitore deve essere inserito presso VSU prima dell'invio&hellip;"</td><td>Il campo <strong>Onboarding Procurement</strong> non è impostato su <strong>Sì</strong>. Impostalo su Sì nella scheda Informazioni Fornitore e salva.</td></tr>
        <tr><td>"Impossibile inviare: è richiesto un ID Fornitore (VID) valido (4-8 cifre)&hellip;"</td><td>L'<strong>ID Fornitore</strong> è mancante o non è di 4&ndash;8 cifre. Inserisci un VID valido dal procurement.</td></tr>
        <tr><td>"Solo le richieste in bozza possono essere inviate per la revisione."</td><td>Il fornitore è già oltre la Bozza. Puoi inviare solo una richiesta che è ancora in stato <strong>Bozza</strong>.</td></tr>
        <tr><td>Il pulsante <strong>Invia per Revisione</strong> non è visibile</td><td>Appare solo per i fornitori in <strong>Bozza</strong> quando hai il permesso di modifica.</td></tr>
        <tr><td>"La Revisione AI può essere forzata solo per i fornitori i cui servizi utilizzano AI."</td><td>Imposta <strong>I Servizi Utilizzano AI</strong> su <strong>Sì</strong> nel fornitore prima di forzare la Revisione AI.</td></tr>
        <tr><td>Non riesco a vedere il modulo GRC nella barra laterale</td><td>Devi essere nel gruppo <strong>Administrator</strong>, <strong>Cyber GRC</strong> o <strong>Auditor</strong>. Chiedi a un amministratore.</td></tr>
        <tr><td>La mia modifica della lingua non è stata salvata</td><td>Assicurati di aver fatto clic su <strong>Aggiorna Lingua</strong> (non solo cambiato il menu a discesa) e che la lingua sia abilitata dal tuo amministratore.</td></tr>
        <tr><td>Il "Test Connessione" di Grip fallisce</td><td>Verifica che l'URL base termini con <code>/public/saas</code> e che il token API sia valido e aggiornato.</td></tr>
        <tr><td>Negare un'app Shadow SaaS non l'ha bloccata in Zscaler</td><td>Il blocco Zscaler deve essere abilitato e configurato, e la <strong>Categoria URL</strong> nominata deve già esistere in Zscaler.</td></tr>
        <tr><td>Il procurement non ha ricevuto l'email digest</td><td>Conferma che il digest sia abilitato con i destinatari in <strong>Admin &rarr; Impostazioni Email</strong> e che le impostazioni SMTP di <strong>Admin &rarr; Email</strong> siano corrette.</td></tr>
        <tr><td>Il pulsante Aggiorna dice "Impossibile recuperare il manifest"</td><td>Un problema di registry/rete nel raggiungere il tuo image registry. Verifica il <strong>Nome Host del Registry</strong> in <strong>Admin &rarr; Versione</strong> e che l'host possa raggiungerlo.</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>Ancora bloccato?</strong> Annota il messaggio esatto sullo schermo e la pagina in cui ti trovavi, poi contatta l'amministratore della piattaforma. Gli amministratori possono consultare <strong>Admin &rarr; Log Attività</strong> per i dettagli.
    </div>
</div>


<!-- ================================================================
     GLOSSARY
     ================================================================ -->
<div class="doc-section" id="glossary">
    <h2>Glossario</h2>
    <table class="doc-table">
        <tr><th>Termine</th><th>Definizione</th></tr>
        <tr><td><strong>ACL</strong></td><td>Access Control List &mdash; definisce le azioni che gli utenti di un gruppo possono eseguire</td></tr>
        <tr><td><strong>Action Plan (Piano d'Azione)</strong></td><td>Una scheda per fornitore per pianificare azioni di follow-up (contatto, invio valutazione, forzatura revisione annuale) con date di scadenza, responsabili e note di stato; attivata quotidianamente dal job Vendor Remediation Schedule</td></tr>
        <tr><td><strong>AI Review</strong></td><td>Uno stato di onboarding fornitore per i fornitori i cui servizi usano AI, tracciati separatamente durante la revisione</td></tr>
        <tr><td><strong>Valutazione</strong></td><td>Una valutazione di conformità in un determinato momento utilizzando il questionario unificato</td></tr>
        <tr><td><strong>CIS Controls</strong></td><td>Center for Internet Security Controls &mdash; un insieme prioritizzato di best practice di sicurezza</td></tr>
        <tr><td><strong>CMMC</strong></td><td>Cybersecurity Maturity Model Certification &mdash; richiesta per i contractor del Dipartimento della Difesa degli Stati Uniti</td></tr>
        <tr><td><strong>Stato di Conformità</strong></td><td>Se un requisito è Conforme, Parziale, Non Conforme, Non Applicabile o Non Valutato</td></tr>
        <tr><td><strong>Controllo</strong></td><td>Una misura di sicurezza specifica implementata per soddisfare i requisiti di conformità</td></tr>
        <tr><td><strong>Crosswalk</strong></td><td>Una mappatura tra due framework che mostra quali requisiti si sovrappongono</td></tr>
        <tr><td><strong>CSF</strong></td><td>NIST Cybersecurity Framework &mdash; un framework di gestione del rischio di sicurezza informatica ampiamente utilizzato</td></tr>
        <tr><td><strong>Custom Field / Custom Data (Campo Personalizzato / Dati Personalizzati)</strong></td><td>Un campo di onboarding specifico dell'organizzazione senza una colonna fornitore standard; acquisito per fornitore e mostrato nella scheda Dati Personalizzati del fornitore, con visibilità per ruolo (vedi <a href="#custom-onboarding">Campi di Onboarding Personalizzati</a>)</td></tr>
        <tr><td><strong>Dominio</strong></td><td>Una categoria di domande sulla sicurezza (es. Governance, Identity &amp; Access Management)</td></tr>
        <tr><td><strong>Prova</strong></td><td>Documenti, screenshot o file che comprovano un'affermazione di conformità</td></tr>
        <tr><td><strong>FAIR</strong></td><td>Factor Analysis of Information Risk &mdash; una metodologia di analisi quantitativa del rischio</td></tr>
        <tr><td><strong>FairScore</strong></td><td>Il punteggio di maturità complessivo della piattaforma calcolato dalle risposte della valutazione</td></tr>
        <tr><td><strong>Riscontro</strong></td><td>Un problema scoperto durante un audit (non conformità, osservazione, opportunità o punto di forza)</td></tr>
        <tr><td><strong>Framework</strong></td><td>Uno standard di conformità come SOC 2, ISO 27001, PCI DSS, ecc.</td></tr>
        <tr><td><strong>GRC</strong></td><td>Governance, Risk, and Compliance</td></tr>
        <tr><td><strong>Grip</strong></td><td>Grip Security &mdash; un servizio che scopre le app SaaS in uso; può alimentare l'elenco Shadow SaaS (vedi <a href="#shadow-saas-grip">Integrazione Grip Shadow SaaS</a>)</td></tr>
        <tr><td><strong>Hero</strong></td><td>HERO Security &mdash; un servizio alternativo di scoperta Shadow SaaS che può alimentare l'elenco Shadow SaaS (si esclude a vicenda con Grip; vedi <a href="#shadow-saas-hero">Integrazione Hero Shadow SaaS</a>)</td></tr>
        <tr><td><strong>HIPAA</strong></td><td>Health Insurance Portability and Accountability Act &mdash; legge statunitense sulla protezione dei dati sanitari</td></tr>
        <tr><td><strong>ISO 27001</strong></td><td>Standard internazionale per i sistemi di gestione della sicurezza delle informazioni</td></tr>
        <tr><td><strong>Livello di Maturità</strong></td><td>Un punteggio da 1 a 4 che indica quanto matura è una pratica di sicurezza (1=Ad Hoc, 4=Ottimizzata)</td></tr>
        <tr><td><strong>NIST 800-171</strong></td><td>Linee guida NIST per la protezione delle Informazioni Non Classificate Controllate (CUI)</td></tr>
        <tr><td><strong>PCI DSS</strong></td><td>Payment Card Industry Data Security Standard</td></tr>
        <tr><td><strong>PII</strong></td><td>Personally Identifiable Information &mdash; Informazioni di Identificazione Personale (nomi, email, indirizzi, ecc.)</td></tr>
        <tr><td><strong>Onboarding Procurement</strong></td><td>Conferma (Sì/No) che un fornitore è stato configurato attraverso il tuo processo di procurement; richiesto, insieme a un VID valido, prima che un fornitore possa essere inviato per la revisione. (Etichettato "VSU Onboarded" sulle istanze aggiornate da versioni precedenti.)</td></tr>
        <tr><td><strong>Requisito</strong></td><td>Una clausola specifica o un obiettivo di controllo all'interno di un framework di conformità</td></tr>
        <tr><td><strong>SaaS</strong></td><td>Software as a Service &mdash; applicazioni cloud accessibili tramite web</td></tr>
        <tr><td><strong>Shadow SaaS</strong></td><td>App SaaS utilizzate nell'organizzazione che non sono mai state formalmente approvate o valutate</td></tr>
        <tr><td><strong>SOC 2</strong></td><td>Service Organization Control Type 2 &mdash; criteri di trust services per le organizzazioni di servizi</td></tr>
        <tr><td><strong>SPII</strong></td><td>Sensitive PII &mdash; PII Sensibili (codici fiscali, dati finanziari, cartelle cliniche)</td></tr>
        <tr><td><strong>SRS</strong></td><td>Security Risk Scorecard &mdash; la valutazione/il voto di sicurezza esterno della piattaforma per un fornitore</td></tr>
        <tr><td><strong>SSC (SecurityScorecard)</strong></td><td>Un voto di sicurezza in lettere di terze parti (A&ndash;F) mostrato per le app e i fornitori scoperti da Grip quando Grip è connesso</td></tr>
        <tr><td><strong>Subprocessor (Subprocessore)</strong></td><td>Il fornitore a valle di un fornitore; lo stesso subprocessore condiviso tra più dei tuoi fornitori indica una concentrazione della catena di approvvigionamento (vedi <a href="#tprm-fourth-party">Rischio di Quarte Parti</a>)</td></tr>
        <tr><td><strong>TPRM</strong></td><td>Third Party Risk Management</td></tr>
        <tr><td><strong>Domanda Unificata</strong></td><td>Una singola domanda di sicurezza che mappa ai requisiti su più framework</td></tr>
        <tr><td><strong>VID</strong></td><td>Vendor ID &mdash; un identificatore di 4&ndash;8 cifre assegnato a un fornitore dal tuo sistema di procurement</td></tr>
        <tr><td><strong>VSU</strong></td><td>La funzione di procurement/configurazione-fornitore; "inserito presso VSU" significa che il fornitore ha completato l'Onboarding Procurement</td></tr>
        <tr><td><strong>Zscaler</strong></td><td>Un servizio di sicurezza web che può bloccare i domini dei siti web; integrato in modo che le app non autorizzate possano essere bloccate su Nega (vedi <a href="#zscaler">Blocco Zscaler</a>)</td></tr>
    </table>
</div>


                </div><!-- /.doc-body -->

                <!-- Sticky right-side TOC (screen only) -->
                <nav class="doc-sidebar-toc" id="sidebarToc">
                    <h4>Per Iniziare</h4>
                    <a href="#overview">Panoramica della Piattaforma</a>
                    <a href="#navigation">Navigazione nella Barra Laterale</a>
                    <a href="#roles">Ruoli Utente &amp; Permessi</a>
                    <a href="#first-login">Il Tuo Primo Accesso</a>
                    <a href="#whats-new">Novità in 2.6.2</a>
                    <a href="#language">Cambiare la Lingua</a>
                    <a href="#question-types">Tipi di Domanda Telefono &amp; VAT</a>

                    <h4>GRC — Guida Rapida</h4>
                    <a href="#grc-overview">Cos'è GRC?</a>
                    <a href="#grc-getting-started">Per Iniziare</a>
                    <a href="#grc-step1">Passo 1: Crea Valutazione</a>
                    <a href="#grc-step2">Passo 2: Rispondi alle Domande</a>
                    <a href="#grc-step3">Passo 3: Carica Prove</a>
                    <a href="#grc-step4">Passo 4: Visualizza Punteggi</a>
                    <a href="#grc-step5">Passo 5: Genera Report</a>

                    <h4>GRC — Funzionalità</h4>
                    <a href="#grc-fairscore">Punteggio di Maturità CSF</a>
                    <a href="#grc-gaps">Analisi delle Lacune</a>
                    <a href="#grc-frameworks">Framework</a>
                    <a href="#grc-controls">Controlli Interni</a>
                    <a href="#grc-crosswalk">Crosswalk dei Framework</a>
                    <a href="#grc-evidence">Libreria delle Prove</a>
                    <a href="#grc-policies">Gestione Policy</a>
                    <a href="#grc-audits">Audit &amp; Riscontri</a>
                    <a href="#grc-risks">Registro dei Rischi</a>
                    <a href="#grc-monitors">Monitor Continui</a>
                    <a href="#grc-tasks">Casella delle Attività</a>
                    <a href="#grc-dashboard">Dashboard GRC</a>

                    <h4>Modulo TPRM</h4>
                    <a href="#tprm-overview">Cos'è TPRM?</a>
                    <a href="#tprm-add-vendor">Aggiungere un Fornitore</a>
                    <a href="#tprm-lifecycle">Ciclo di Vita del Fornitore</a>
                    <a href="#tprm-assessments">Valutazioni dei Fornitori</a>
                    <a href="#assessment-forms">Moduli di Valutazione &amp; Compilazione AI</a>
                    <a href="#tprm-action-plan">Piano d'Azione Fornitore</a>
                    <a href="#tprm-srs">Security Risk Scorecard</a>
                    <a href="#tprm-fair">Analisi FAIR</a>
                    <a href="#tprm-fourth-party">Rischio di Quarte Parti</a>
                    <a href="#tprm-shadow-saas">Shadow SaaS</a>

                    <h4>Onboarding &amp; Procurement</h4>
                    <a href="#onboarding-workflow">Onboarding Fornitore</a>
                    <a href="#custom-onboarding">Campi di Onboarding Personalizzati</a>
                    <a href="#ai-review">Revisione AI</a>
                    <a href="#procurement-cyber-status">Stato Cyber Procurement</a>
                    <a href="#shadow-saas-grip">Integrazione Grip Shadow SaaS</a>
                    <a href="#shadow-saas-hero">Integrazione Hero Shadow SaaS</a>
                    <a href="#zscaler">Blocco Zscaler</a>
                    <a href="#breach-alerts">Avvisi di Violazione / Cyber</a>

                    <h4>Portale Admin</h4>
                    <a href="#admin-general">Impostazioni Generali</a>
                    <a href="#admin-branding">Branding &amp; Tema</a>
                    <a href="#admin-users">Gestione Utenti</a>
                    <a href="#admin-acl-groups">Gruppi ACL</a>
                    <a href="#admin-templates">Template di Valutazione</a>
                    <a href="#admin-email">Configurazione Email</a>
                    <a href="#admin-saml">SAML / SSO</a>
                    <a href="#admin-ai">Integrazione AI</a>
                    <a href="#admin-backup">Backup di Database di Grandi Dimensioni</a>
                    <a href="#admin-updates">Aggiornamento della Piattaforma</a>

                    <h4>Aiuto &amp; Riferimento</h4>
                    <a href="#faq">FAQ</a>
                    <a href="#troubleshooting">Risoluzione dei Problemi</a>
                    <a href="#glossary">Glossario</a>
                </nav>

