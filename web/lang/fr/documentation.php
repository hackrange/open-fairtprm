
                <!-- Print-only: Cover Page -->
                <div class="print-cover">
                    <img class="cover-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
                    <div class="cover-rule"></div>
                    <h1>Documentation de la plateforme</h1>
                    <div class="cover-edition">Gouvernance, Risque &amp; Conformité &bull; Gestion des risques tiers</div>
                    <div class="cover-version">Version 2.6.2</div>
                    <div class="cover-rule-bottom"></div>
                    <div class="cover-meta">
                        <strong>Date :</strong> <?php echo date('F j, Y'); ?><br>
                        <strong>Classification :</strong> Usage interne uniquement<br>
                        <strong>Préparé par :</strong> Équipe d'administration GRC
                    </div>
                </div>

                <!-- Print-only: Introduction & Purpose -->
                <div class="print-intro">
                    <h2>Introduction</h2>

                    <h3>Objectif</h3>
                    <p>Ce document fournit une documentation complète pour la plateforme Fair TPRM &amp; GRC. Il sert à la fois de guide utilisateur et de manuel de référence pour l'ensemble du personnel impliqué dans la gestion des risques tiers, la gouvernance, l'évaluation des risques et les opérations de conformité.</p>
                    <p>Le public visé comprend les analystes GRC, les responsables de la conformité, les auditeurs, le personnel de sécurité informatique, les équipes d'approvisionnement et les administrateurs système. Que vous réalisiez votre première évaluation de conformité ou que vous gériez un programme d'audit en cours, ce guide fournit les instructions étape par étape dont vous avez besoin.</p>

                    <h3>Périmètre</h3>
                    <p>Cette documentation couvre les modules et capacités suivants de la plateforme :</p>
                    <ul>
                        <li><strong>Module GRC</strong> &mdash; Évaluations de conformité unifiées sur plusieurs référentiels (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, NIST 800-171), gestion des contrôles internes, collecte de preuves, gestion du cycle de vie des politiques, gestion des audits, registre des risques, surveillance continue et notation de maturité</li>
                        <li><strong>Module TPRM</strong> &mdash; Intégration des fournisseurs tiers, hiérarchisation des risques, évaluations de sécurité, analyse quantitative des risques (FAIR), notation externe de la sécurité, suivi des risques de quatrième partie et découverte du Shadow SaaS</li>
                        <li><strong>Portail d'administration</strong> &mdash; Configuration du système, gestion des utilisateurs et des groupes, image de marque, paramètres de messagerie, intégration SSO/SAML et configuration de la plateforme IA</li>
                    </ul>

                    <h3>Comment utiliser ce guide</h3>
                    <p>Ce guide est organisé en quatre parties. La <strong>Partie 1 (Démarrage)</strong> couvre la navigation dans la plateforme, les rôles utilisateurs et votre première connexion. La <strong>Partie 2 (Module GRC)</strong> fournit une présentation détaillée du processus d'évaluation de la conformité, depuis la création de votre première évaluation jusqu'à la collecte de preuves, la notation et la génération de rapports. La <strong>Partie 3 (Module TPRM)</strong> couvre la gestion des risques fournisseurs. La <strong>Partie 4 (Portail d'administration)</strong> couvre l'administration du système.</p>
                    <p>Si vous êtes nouveau sur la plateforme, commencez par la section <em>Démarrage</em>, puis suivez le guide de démarrage rapide GRC en cinq étapes. Chaque étape comprend des instructions précises, clic par clic.</p>

                    <h3>Conventions du document</h3>
                    <p>Tout au long de ce document, les conventions suivantes sont utilisées :</p>
                    <ul>
                        <li><strong>Le texte en gras</strong> indique des concepts importants ou une mise en évidence</li>
                        <li><code>La mise en forme de code</code> indique des valeurs que vous saisissez ou des références générées par le système</li>
                        <li>Les listes d'étapes numérotées indiquent des procédures séquentielles à suivre dans l'ordre</li>
                        <li>Les encadrés fournissent des conseils, des avertissements et des informations contextuelles importantes</li>
                    </ul>
                </div>

                <!-- Print-only: Table of Contents (populated by JS) -->
                <div class="print-toc">
                    <h2>Table des matières</h2>
                    <div id="tocBody"></div>
                </div>

                <!-- Main content body -->
                <div class="doc-body">

                <h1>Documentation de la plateforme</h1>
                <p class="doc-subtitle">Fair TPRM &amp; GRC Platform &mdash; Version 2.6.2 &mdash; Dernière mise à jour : <?php echo date('F j, Y'); ?></p>

                <div style="margin-bottom:20px;">
                    <button class="btn-doc" id="printBtn">Télécharger le PDF</button>
                </div>

<!-- old doc-toc removed — print-toc above now serves as the on-screen TOC -->


<!-- ================================================================
     GETTING STARTED
     ================================================================ -->
<div class="doc-section" id="overview">
    <h2>Présentation de la plateforme</h2>
    <p>Cette plateforme fournit deux modules intégrés pour gérer la posture de sécurité de votre organisation :</p>
    <ul>
        <li><strong>TPRM (Gestion des risques tiers)</strong> &mdash; Suivez, évaluez et notez vos fournisseurs et prestataires. Comprenez le risque de sécurité que chaque fournisseur représente pour votre organisation.</li>
        <li><strong>GRC (Gouvernance, Risque &amp; Conformité)</strong> &mdash; Gérez les référentiels de conformité (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, et plus encore), répondez à un questionnaire unifié unique couvrant simultanément tous les référentiels, suivez les contrôles internes, importez des preuves, gérez les politiques et réalisez des audits.</li>
    </ul>
    <p>Les administrateurs ont également accès au <strong>Portail d'administration</strong> pour la configuration du système, la gestion des utilisateurs, les intégrations et la maintenance.</p>

    <div class="callout callout-success">
        <strong>Concept clé &mdash; Une seule évaluation, plusieurs référentiels :</strong> Le module GRC utilise un <em>questionnaire d'évaluation unifié</em> comportant 146 questions réparties sur 14 domaines de sécurité. Lorsque vous répondez à ces questions une seule fois, la plateforme calcule automatiquement votre pourcentage de conformité pour chaque référentiel pris en charge (SOC 2, ISO 27001, PCI DSS, etc.) &mdash; aucune duplication de travail n'est nécessaire.
    </div>
</div>

<div class="doc-section" id="navigation">
    <h2>Navigation dans la barre latérale</h2>
    <p>La barre latérale gauche est votre principal outil de navigation. Elle est organisée en modules et sections réductibles :</p>
    <ol class="steps">
        <li>En haut de la barre latérale, vous voyez le logo et le texte de marque de votre entreprise.</li>
        <li>En dessous se trouvent deux en-têtes de modules réductibles : <span class="menu-label">Module TPRM</span> et <span class="menu-label">Module GRC</span>. Cliquez sur l'un ou l'autre en-tête pour le développer ou le réduire. Votre navigateur mémorise quels modules sont ouverts.</li>
        <li>Dans chaque module, il y a des <strong>sections</strong> réductibles (par ex. « Conformité », « Preuves &amp; Surveillance », « Évaluation &amp; Audit »). Cliquez sur le titre d'une section pour la développer et voir les liens de navigation qu'elle contient.</li>
        <li>En bas de la barre latérale, vous trouverez des liens utilitaires : <span class="menu-label">Tableau de bord</span>, <span class="menu-label">Profil</span>, <span class="menu-label">Documentation</span> (cette page) et <span class="menu-label">Administration</span> (administrateurs uniquement).</li>
    </ol>

    <h3>Structure de la barre latérale du module GRC</h3>
    <p>Lorsque vous développez <span class="menu-label">Module GRC</span>, vous verrez ces sections :</p>
    <table class="doc-table">
        <tr><th>Section</th><th>Pages incluses</th><th>Contenu</th></tr>
        <tr><td><strong>Conformité</strong></td><td>Tableau de bord GRC, Référentiels, Contrôles internes, Correspondance des référentiels</td><td>Vue d'ensemble de votre posture de conformité, gestion des référentiels, bibliothèque de contrôles et cartographie inter-référentiels</td></tr>
        <tr><td><strong>Preuves &amp; Surveillance</strong></td><td>Bibliothèque de preuves, Moniteurs continus</td><td>Importez et gérez les preuves de conformité ; configurez les vérifications de conformité automatisées</td></tr>
        <tr><td><strong>Gestion des politiques</strong></td><td>Politiques</td><td>Créez, versionnez, approuvez et publiez les politiques organisationnelles</td></tr>
        <tr><td><strong>Évaluation &amp; Audit</strong></td><td>Score de maturité CSF, Questionnaire d'évaluation, Boîte de réception des tâches, Audits, Constatations, Registre des risques</td><td>Le questionnaire d'évaluation unifié, les tableaux de bord de maturité, les audits et le suivi des risques</td></tr>
    </table>
</div>

<div class="doc-section" id="roles">
    <h2>Rôles utilisateurs &amp; Permissions</h2>
    <p>Les utilisateurs sont affectés à un ou plusieurs <strong>Groupes ACL</strong> qui déterminent ce qu'ils peuvent voir et faire. Un administrateur affecte les groupes via <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Utilisateurs</span> &rarr; bouton <span class="btn-label">Groupes</span>.</p>
    <table class="doc-table">
        <tr><th>Groupe</th><th>Ce que vous pouvez faire</th></tr>
        <tr><td><strong>Administrateur</strong></td><td>Accès complet à tout &mdash; tous les modules, les paramètres d'administration, la gestion des utilisateurs et la configuration du système</td></tr>
        <tr><td><strong>Cyber TPRM</strong></td><td>Accès complet au module TPRM &mdash; créer/modifier/supprimer des fournisseurs, réaliser des évaluations, analyse FAIR, notation</td></tr>
        <tr><td><strong>Cyber GRC</strong></td><td>Accès complet au module GRC &mdash; gérer les référentiels, réaliser des évaluations, importer des preuves, gérer les politiques, réaliser des audits, gérer les risques</td></tr>
        <tr><td><strong>Contributeurs GRC</strong></td><td>Accès GRC limité &mdash; accomplir les tâches assignées, fournir des preuves, répondre aux questions d'évaluation assignées</td></tr>
        <tr><td><strong>Auditeur</strong></td><td><strong>Accès en lecture seule</strong> aux modules TPRM et GRC &mdash; peut tout voir, télécharger des preuves et générer des rapports, mais ne peut pas créer, modifier ou supprimer</td></tr>
        <tr><td><strong>Approvisionnement</strong></td><td>Créer et gérer les demandes d'intégration des fournisseurs, importer des documents fournisseurs</td></tr>
        <tr><td><strong>Partie prenante</strong></td><td>Consulter leurs propres demandes fournisseurs et répondre aux tâches qui leur sont assignées</td></tr>
    </table>

    <div class="callout callout-info">
        <strong>Pour voir le module GRC dans la barre latérale :</strong> Vous devez appartenir au groupe <strong>Administrateur</strong>, <strong>Cyber GRC</strong> ou <strong>Auditeur</strong>. Si vous ne voyez pas le module GRC dans la barre latérale, demandez à votre administrateur de vous ajouter à l'un de ces groupes.
    </div>
</div>

<div class="doc-section" id="first-login">
    <h2>Votre première connexion</h2>
    <ol class="steps">
        <li>Ouvrez votre navigateur web et accédez à l'URL de votre plateforme (par ex. <code>https://tprm.yourcompany.com</code>).</li>
        <li>Saisissez votre <span class="field-label">Nom d'utilisateur</span> et votre <span class="field-label">Mot de passe</span> fournis par votre administrateur.</li>
        <li>Si l'authentification à deux facteurs (TOTP) est activée pour votre compte, ouvrez votre application d'authentification (Google Authenticator, Microsoft Authenticator, etc.) et saisissez le code à 6 chiffres lorsqu'il vous est demandé.</li>
        <li>Vous arriverez sur le <strong>Tableau de bord</strong>. La barre supérieure affiche « Bienvenue, [Votre nom] » avec des liens vers Admin (si vous êtes administrateur), Profil et Déconnexion.</li>
        <li>Regardez la barre latérale gauche. Si vous êtes dans le groupe <strong>Cyber GRC</strong> ou <strong>Administrateur</strong>, vous verrez <span class="menu-label">Module GRC</span> dans la barre latérale. Cliquez dessus pour développer la navigation GRC.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/dashboard.png" alt="The platform dashboard after logging in" loading="lazy">
        <figcaption><strong>Le tableau de bord.</strong> Après votre connexion, vous arrivez ici. La barre supérieure (en haut à droite) contient <strong>Admin</strong>, <strong>Profil</strong> et <strong>Déconnexion</strong>. La barre latérale gauche est votre menu principal.</figcaption>
    </figure>
</div>


<!-- ================================================================
     WHAT'S NEW IN 2.6.2
     ================================================================ -->
<div class="doc-section" id="whats-new">
    <h2>Nouveautés de la version 2.6.2</h2>
    <p>La version 2.6.2 ajoute plusieurs fonctionnalités axées sur <strong>l'intégration des fournisseurs, la collaboration avec l'approvisionnement, le support multilingue et la découverte du Shadow SaaS</strong>. Si vous avez utilisé une version antérieure, voici ce qui est nouveau. Chaque élément renvoie à sa présentation complète plus loin dans ce guide.</p>
    <table class="doc-table">
        <tr><th>Nouvelle fonctionnalité</th><th>Ce qu'elle fait</th><th>Pour qui</th></tr>
        <tr><td><strong><a href="#language">Paramètres de langue</a></strong></td><td>Utilisez la plateforme dans 8 langues. Chaque personne choisit sa propre langue ; les administrateurs choisissent quelles langues sont disponibles.</td><td>Tout le monde</td></tr>
        <tr><td><strong><a href="#onboarding-workflow">Intégration par l'approvisionnement &amp; ID fournisseur</a></strong></td><td>Un fournisseur doit être intégré via l'approvisionnement et disposer d'un ID fournisseur (VID) valide avant de pouvoir être soumis pour examen cyber.</td><td>Approvisionnement, Parties prenantes</td></tr>
        <tr><td><strong><a href="#ai-review">Examen IA pour les fournisseurs</a></strong></td><td>Un statut de révision dédié pour les fournisseurs dont les services utilisent l'IA, plus une action « Forcer l'examen IA ».</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#procurement-cyber-status">Statut cyber de l'approvisionnement</a></strong></td><td>Une page en direct affichant les fournisseurs en cours d'examen, avec un historique des mises à jour que l'équipe cyber partage avec l'approvisionnement, ainsi qu'un résumé hebdomadaire par e-mail.</td><td>Approvisionnement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#shadow-saas-grip">Intégration Grip Shadow SaaS</a></strong></td><td>Découvrez automatiquement les applications SaaS utilisées dans votre organisation et intégrez-les dans la liste Shadow SaaS.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#shadow-saas-hero">Intégration Hero Shadow SaaS</a></strong></td><td>Un fournisseur alternatif de Shadow SaaS : découvrez les fournisseurs et les problèmes de sécurité depuis HERO Security et alimentez-les dans la même liste Shadow SaaS. Grip et Hero sont mutuellement exclusifs &mdash; utilisez l'un ou l'autre.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#zscaler">Blocage Zscaler</a></strong></td><td>Bloquez le domaine web d'une application non autorisée directement dans Zscaler en un seul clic.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#admin-updates">Mises à jour intégrées</a></strong></td><td>Vérifiez si une version plus récente est disponible dans votre registre et effectuez la mise à niveau depuis le portail d'administration.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#question-types">Types de questions Téléphone &amp; TVA</a></strong></td><td>Nouveaux types de champs pour les évaluations/intégrations : un numéro de téléphone avec sélecteur d'indicatif pays &amp; drapeau (formaté automatiquement), et un numéro de TVA UE avec double saisie et validation en direct gratuite via le service officiel EU VIES.</td><td>Tout le monde</td></tr>
        <tr><td><strong><a href="#question-types">Données fournisseurs &amp; améliorations de la recherche</a></strong></td><td>Enregistrez un numéro de TVA pour chaque fournisseur (affiché sur la page du fournisseur avec un raccourci « Ajouter TVA »), trouvez des fournisseurs par numéro de TVA dans la recherche rapide, et une bannière de score d'intégration par approvisionnement plus claire.</td><td>Approvisionnement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#admin-backup">Sauvegardes de grandes bases de données</a></strong></td><td>La sauvegarde et la restauration prennent désormais en charge les bases de données de plusieurs gigaoctets et les enregistrements très volumineux sans expiration du délai.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#assessment-forms">Formulaires d'évaluation &amp; Remplissage automatique par IA</a></strong></td><td>Téléchargez une évaluation sous forme de PDF à remplir ou de classeur Excel, réimportez un fichier complété, et &mdash; avec un fournisseur IA &mdash; remplissez automatiquement les réponses à partir des certificats actuels du fournisseur.</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#tprm-action-plan">Plan d'action fournisseur</a></strong></td><td>Planifiez des actions de suivi pour un fournisseur (contacter, envoyer une évaluation, forcer une révision annuelle) avec des dates d'échéance, des responsables, des alertes par e-mail et des notes de statut.</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#custom-onboarding">Champs d'intégration personnalisés &amp; Données personnalisées</a></strong></td><td>Capturez des champs supplémentaires propres à votre organisation sur un fournisseur avec une visibilité par rôle, modifiez-les dans l'onglet Données personnalisées, et lisez-les dans l'export CSV et l'API.</td><td>Admins, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#breach-alerts">Alertes de violation / cyber</a></strong></td><td>Un flux de violations de la chaîne d'approvisionnement (y compris les incidents Grip) avec une analyse détaillée des utilisateurs affectés et des actions groupées d'accusé de réception / faux positif / suppression.</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#admin-acl-groups">Groupes de contrôle d'accès personnalisés</a></strong></td><td>Créez vos propres groupes ACL, clonez les permissions d'un groupe existant et définissez Lecture ou Lecture/Écriture par module. Les groupes fournis sont protégés.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#admin-templates">Générateur de modèles d'évaluation</a></strong></td><td>Nouveaux types de questions (choix multiple, téléphone, TVA), instructions de certificat pilotées par modèle, restriction des champs par rôle, et modèles désactivés masqués par défaut.</td><td>Admins, Cyber TPRM</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>Comment savoir quelle version j'utilise ?</strong> Les administrateurs peuvent aller dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span> pour voir la version installée. Ce guide décrit la version <strong>v2.6.2</strong>. Voir <a href="#admin-updates">Mise à jour de la plateforme</a>.
    </div>
</div>

<div class="doc-section" id="language">
    <h2>Changer votre langue <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>L'interface de la plateforme peut être affichée dans <strong>8 langues</strong>. Chaque personne choisit sa propre langue &mdash; le modifier n'affecte que <em>votre</em> écran, pas celui des autres. Votre choix est mémorisé à chaque connexion.</p>

    <h3>Langues disponibles</h3>
    <table class="doc-table">
        <tr><th>Langue</th><th>Affichée dans le menu comme</th></tr>
        <tr><td>Anglais</td><td>English</td></tr>
        <tr><td>Espagnol</td><td>Espa&ntilde;ol</td></tr>
        <tr><td>Italien</td><td>Italiano</td></tr>
        <tr><td>Ukrainien</td><td>&#1059;&#1082;&#1088;&#1072;&#1111;&#1085;&#1089;&#1100;&#1082;&#1072;</td></tr>
        <tr><td>Chinois (simplifié)</td><td>&#20013;&#25991;&#65288;&#31616;&#20307;&#65289;</td></tr>
        <tr><td>Hindi</td><td>&#2361;&#2367;&#2344;&#2381;&#2342;&#2368;</td></tr>
        <tr><td>Français</td><td>Fran&ccedil;ais</td></tr>
        <tr><td>Portugais</td><td>Portugu&ecirc;s</td></tr>
    </table>
    <p class="callout callout-info" style="margin-top:0;"><strong>Seules les langues que votre administrateur a activées apparaîtront dans votre liste.</strong> L'anglais est toujours disponible et ne peut pas être désactivé.</p>

    <h3>Comment changer votre langue (étape par étape)</h3>
    <ol class="steps">
        <li>Cliquez sur <span class="menu-label">Profil</span> dans le coin supérieur droit de n'importe quelle page.</li>
        <li>Sur la page Profil, faites défiler vers le bas jusqu'à la carte <span class="field-label">Préférence de langue</span>.</li>
        <li>Cliquez sur le menu déroulant <span class="field-label">Langue</span> et choisissez votre langue. Pour revenir à la langue définie par votre administrateur pour tout le monde, choisissez <strong>Langue par défaut du système</strong>.</li>
        <li>Cliquez sur <span class="btn-label">Mettre à jour la langue</span>. La page se recharge et les menus, boutons et étiquettes s'affichent désormais dans la langue choisie.</li>
    </ol>
    <figure class="doc-figure narrow">
        <img src="app/docs/profile-language-card.png" alt="Language Preference card on the Profile page" loading="lazy">
        <figcaption><strong>Profil &rarr; Préférence de langue.</strong> Choisissez une langue et cliquez sur <strong>Mettre à jour la langue</strong>. Choisir <em>Langue par défaut du système</em> supprime votre préférence personnelle.</figcaption>
    </figure>

    <h3>Pour les administrateurs : choisir les langues disponibles</h3>
    <p>Les administrateurs définissent la <strong>langue par défaut</strong> (utilisée pour les nouveaux utilisateurs et pour la page de connexion avant toute connexion) et les langues que tout le monde est autorisé à choisir.</p>
    <ol class="steps">
        <li>Allez dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Général</span>.</li>
        <li>Trouvez le menu déroulant <span class="field-label">Langue par défaut</span> et choisissez la langue par défaut de l'organisation.</li>
        <li>Sous <span class="field-label">Langues activées</span>, cochez les langues que vous souhaitez rendre disponibles. (L'anglais est toujours coché et ne peut pas être désactivé.)</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer la configuration</span>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-general-language.png" alt="Default Language and Enabled Languages settings in Admin General" loading="lazy">
        <figcaption><strong>Admin &rarr; Général.</strong> Définissez la <strong>Langue par défaut</strong> et cochez les <strong>Langues activées</strong> parmi lesquelles les utilisateurs peuvent choisir.</figcaption>
    </figure>

    <div class="callout callout-warning">
        <strong>Bon à savoir :</strong> L'interface est traduite partout où une traduction existe pour votre langue ; une chaîne qui n'a pas encore été traduite revient à l'anglais, vous pouvez donc encore voir occasionnellement une étiquette en anglais. Le contenu que vous ou vos fournisseurs saisissez (noms de fournisseurs, notes, noms de fichiers importés, réponses en texte libre) est toujours affiché exactement tel qu'il a été saisi. Les <em>questions</em> d'évaluation des fournisseurs peuvent être traduites automatiquement pour l'affichage lorsqu'un fournisseur IA est configuré (voir <a href="#admin-ai">Intégration IA</a>) ; sans cela, elles restent dans la langue dans laquelle elles ont été rédigées. Les valeurs des réponses enregistrées restent toujours en anglais afin que la notation et les rapports restent cohérents entre les langues.
    </div>
</div>


<!-- ================================================================
     PHONE & VAT QUESTION TYPES + VENDOR DATA IMPROVEMENTS
     ================================================================ -->
<div class="doc-section" id="question-types">
    <h2>Types de questions Téléphone &amp; TVA <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Le <strong>Générateur de modèles</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Modèles d'évaluation</span>) bénéficie de deux nouveaux types de questions qui collectent les coordonnées et les informations fiscales dans un format propre et cohérent. Ils peuvent être utilisés sur n'importe quel modèle d'évaluation ou d'intégration, et comme les autres questions, ils peuvent être mappés à un champ fournisseur afin que la réponse soit reportée sur la fiche fournisseur.</p>

    <h3>Téléphone</h3>
    <p>Le type <strong>Téléphone</strong> affiche un sélecteur de pays avec un drapeau et un indicatif téléphonique à côté de la zone de numéro. Les États-Unis sont listés en premier ; tous les autres pays suivent par ordre alphabétique. Quel que soit le format saisi &mdash; <code>314-444-5544</code>, <code>(314)&nbsp;444-5544</code> ou <code>3144445544</code> &mdash; le numéro est enregistré dans un format international uniforme (par exemple, en choisissant le drapeau américain et en tapant <code>3144445544</code>, on enregistre <code>+13144445544</code>). Le formulaire de demande d'intégration fournisseur par défaut utilise désormais ce type pour le numéro de téléphone du contact principal, et le champ téléphone de l'<strong>attestation</strong> d'évaluation l'utilise également.</p>

    <h3>TVA (numéro de TVA UE)</h3>
    <p>Le type <strong>TVA</strong> est destiné aux numéros de TVA européens. Pour se prémunir contre les fautes de frappe, il doit être <strong>saisi deux fois</strong>, et les deux saisies doivent correspondre avant d'être enregistrées. Le numéro est enregistré sous une forme cohérente (majuscules, sans espaces ni ponctuation &mdash; par exemple <code>DE123456789</code>).</p>
    <ul>
        <li><strong>Validation en direct gratuite.</strong> Lorsque vous avez fini de saisir, la plateforme vérifie le numéro auprès du service officiel <strong>EU VIES</strong> (le Système d'échange d'informations sur la TVA de la Commission européenne). VIES est gratuit, ne nécessite pas de compte et reflète le registre en direct de chaque État membre.</li>
        <li><strong>Informatif, jamais bloquant.</strong> Si VIES ne peut pas confirmer le numéro, celui-ci est quand même enregistré &mdash; une notice vous demande simplement de le vérifier. Si VIES est momentanément lent ou si le registre d'un pays est temporairement indisponible, le numéro est enregistré et vous êtes invité à le vérifier ultérieurement.</li>
        <li><strong>Détails à la demande.</strong> Lorsque VIES confirme un numéro, un bouton d'information (&#9432;) apparaît à côté. En cliquant dessus, un panneau s'ouvre affichant le nom de l'entreprise enregistrée et l'adresse retournés par VIES.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Mapper la TVA à la fiche fournisseur.</strong> Un champ dédié <code>vat_number</code> est disponible, de sorte qu'une question TVA mappée à ce champ enregistre la valeur sur le fournisseur. Lorsque vous choisissez le type de question TVA dans le Générateur de modèles, ce mapping est sélectionné automatiquement pour vous.
    </div>

    <h3>TVA sur la page fournisseur</h3>
    <p>Le numéro de TVA du fournisseur est affiché dans la carte <strong>Informations fournisseur</strong> sur la page d'intégration du fournisseur. Si aucune TVA n'est enregistrée, un bouton <strong>&ldquo;+ Ajouter TVA&rdquo;</strong> apparaît pour passer directement en mode édition avec le champ TVA en focus.</p>

    <h3>Rechercher des fournisseurs par numéro de TVA</h3>
    <p>La zone de <strong>recherche rapide</strong> en haut à droite de la plateforme permet désormais également de rechercher par numéro de TVA, en plus du nom du fournisseur, du domaine et de la partie prenante. Les correspondances directes sur le nom, le domaine ou le numéro de TVA d'un fournisseur sont toujours affichées en premier.</p>

    <h2>Bannière de score d'intégration par l'approvisionnement <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Lorsque le statut d'<strong>intégration par l'approvisionnement</strong> d'un fournisseur est défini sur <strong>Non</strong>, une bannière indique désormais clairement que <em>la notation automatique du fournisseur est désactivée jusqu'à ce que le fournisseur complète l'intégration par l'approvisionnement</em>. Elle apparaît à la fois sur la page d'intégration du fournisseur et sous la question d'évaluation correspondante, et se met à jour immédiatement en fonction du changement de réponse.</p>

    <h2>Soumettre une évaluation : vérification préalable des champs obligatoires <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Lorsqu'un fournisseur clique sur <strong>Soumettre</strong> dans une évaluation, la plateforme vérifie désormais que toutes les questions obligatoires ont reçu une réponse <em>avant</em> de demander les détails d'attestation du soumetteur. Auparavant, une réponse manquante n'était signalée qu'après que l'attestation était remplie, obligeant à la ressaisir.</p>
</div>

<!-- ================================================================
     LARGE DATABASE BACKUPS
     ================================================================ -->
<div class="doc-section" id="admin-backup">
    <h2>Sauvegardes et restaurations de grandes bases de données <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>La sauvegarde et la restauration (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Sauvegarde</span>) gèrent désormais les <strong>bases de données de plusieurs gigaoctets</strong> et les enregistrements individuels approchant <strong>1&nbsp;Go</strong> sans que l'opération ne soit interrompue par un délai d'expiration ou un manque de mémoire. En coulisses, la limite de paquets de la base de données, les délais d'expiration réseau, la taille de téléchargement et les limites de durée des requêtes ont tous été augmentés pour accueillir des données très volumineuses.</p>
    <div class="callout callout-info">
        <strong>Pour les très grandes bases de données :</strong> La sauvegarde ou la restauration d'un fichier de plusieurs gigaoctets peut prendre un certain temps &mdash; laissez la page ouverte jusqu'à la fin. Les ensembles de données extrêmement volumineux (dizaines de gigaoctets) sont mieux restaurés depuis la ligne de commande du serveur.
    </div>
</div>


<!-- ================================================================
     GRC MODULE - COMPREHENSIVE DOCUMENTATION
     ================================================================ -->
<div class="doc-section" id="grc-overview">
    <h2>Module GRC : Qu'est-ce que la Gouvernance, le Risque &amp; la Conformité ?</h2>
    <p><strong>GRC</strong> signifie <strong>Gouvernance, Risque et Conformité</strong>. C'est la pratique consistant à s'assurer que votre organisation respecte les exigences réglementaires, suit les meilleures pratiques de sécurité, gère les risques et peut prouver sa conformité aux auditeurs et aux régulateurs.</p>

    <p>Le module GRC vous aide à :</p>
    <ul>
        <li><strong>Évaluer votre maturité en matière de sécurité</strong> à l'aide d'un questionnaire unifié unique qui s'applique simultanément à plusieurs référentiels de conformité</li>
        <li><strong>Suivre la conformité</strong> par rapport à SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, et plus encore</li>
        <li><strong>Gérer les contrôles internes</strong> &mdash; documenter les mesures de sécurité que votre organisation a mises en place</li>
        <li><strong>Collecter et stocker des preuves</strong> &mdash; importer des captures d'écran, des exports de configuration, des documents de politique et des certificats qui prouvent la conformité</li>
        <li><strong>Gérer les politiques</strong> &mdash; créer, versionner, approuver et publier les politiques de sécurité organisationnelles</li>
        <li><strong>Réaliser des audits</strong> &mdash; planifier les audits, enregistrer les constatations, affecter des mesures correctives et suivre leur clôture</li>
        <li><strong>Suivre les risques</strong> &mdash; maintenir un registre des risques avec notation de la probabilité/impact et plans de traitement</li>
        <li><strong>Surveiller en continu</strong> &mdash; mettre en place des vérifications automatisées qui contrôlent les contrôles de conformité selon un calendrier</li>
    </ul>

    <div class="callout callout-warning">
        <strong>Concept important &mdash; Questions unifiées :</strong> La plateforme contient <strong>146 questions de sécurité unifiées</strong> organisées en <strong>14 domaines de sécurité</strong> (Gouvernance, Gestion des identités &amp; des accès, Sécurité des données, Sécurité réseau, etc.). Chaque question est pré-mappée aux exigences spécifiques de plusieurs référentiels de conformité. Lorsque vous répondez à une question une seule fois, la réponse s'applique automatiquement à chaque référentiel auquel cette question est mappée. Cela élimine le besoin de répondre à la même question séparément pour SOC 2, ISO 27001 et PCI DSS.
    </div>
</div>


<div class="doc-section" id="grc-getting-started">
    <h2>Démarrer avec GRC &mdash; Guide de démarrage rapide</h2>
    <p>Si vous êtes tout nouveau dans le module GRC, suivez ces étapes dans l'ordre. À la fin, vous aurez une évaluation de conformité complète avec des scores pour tous les référentiels.</p>

    <div class="callout callout-info">
        <strong>Prérequis :</strong><br>
        &bull; Vous devez être connecté en tant qu'utilisateur appartenant au groupe <strong>Administrateur</strong> ou <strong>Cyber GRC</strong><br>
        &bull; Vous devez pouvoir voir <span class="menu-label">Module GRC</span> dans la barre latérale gauche<br>
        &bull; Si vous ne le voyez pas, demandez à votre administrateur de vous affecter au groupe Cyber GRC (Admin &rarr; Utilisateurs &rarr; cliquez sur le bouton Groupes à côté de votre nom &rarr; cochez « Cyber GRC » &rarr; Enregistrer)
    </div>

    <p>Le flux de travail recommandé est :</p>
    <ol>
        <li><strong>Créer une évaluation</strong> &mdash; Cela définit le périmètre et l'objectif de votre revue de conformité</li>
        <li><strong>Répondre aux questions</strong> &mdash; Parcourez les 146 questions unifiées en évaluant votre niveau de maturité pour chacune</li>
        <li><strong>Importer des preuves</strong> &mdash; Joignez des documents, captures d'écran et fichiers qui prouvent vos réponses</li>
        <li><strong>Consulter vos scores</strong> &mdash; Vérifiez vos pourcentages de conformité sur la page Référentiels</li>
        <li><strong>Générer des rapports</strong> &mdash; Créez des rapports de conformité détaillés par référentiel à destination des auditeurs</li>
    </ol>
    <p>Chaque étape est expliquée en détail ci-dessous.</p>
</div>


<div class="doc-section" id="grc-step1">
    <h2>Étape 1 : Créer votre première évaluation</h2>
    <p>Une <strong>Évaluation</strong> est une revue de conformité de votre organisation. Elle représente une évaluation à un moment précis au cours de laquelle vous répondez à des questions de sécurité, enregistrez des niveaux de maturité et collectez des preuves. Considérez-la comme un « instantané de conformité ».</p>

    <h3>Comment créer une nouvelle évaluation</h3>
    <ol class="steps">
        <li>Dans la barre latérale gauche, cliquez sur <span class="menu-label">Module GRC</span> pour le développer.</li>
        <li>Cliquez sur la section <span class="menu-label">Évaluation &amp; Audit</span> pour la développer.</li>
        <li>Cliquez sur <span class="menu-label">Questionnaire d'évaluation</span>. Cela ouvre la page principale d'évaluation.</li>
        <li>En haut de la page, vous verrez un bouton <span class="btn-label">+ Nouvelle évaluation</span>. Cliquez dessus.</li>
        <li>Un formulaire apparaîtra. Remplissez les champs suivants :
            <ul>
                <li><span class="field-label">Titre</span> &mdash; Donnez à votre évaluation un nom descriptif. Exemple : <code>2026 Annual Security Assessment - ACME Corp</code></li>
                <li><span class="field-label">Type d'évaluation</span> &mdash; Sélectionnez le type d'évaluation :
                    <ul>
                        <li><strong>Initiale</strong> &mdash; Votre toute première évaluation (recommandée pour les nouveaux utilisateurs)</li>
                        <li><strong>Périodique</strong> &mdash; Une évaluation récurrente régulière (par ex., revue annuelle)</li>
                        <li><strong>Ciblée</strong> &mdash; Une évaluation focalisée sur un domaine spécifique</li>
                        <li><strong>Pré-audit</strong> &mdash; Préparation avant un audit formel</li>
                        <li><strong>Certification</strong> &mdash; Évaluation à des fins de certification (par ex., SOC 2 Type II)</li>
                    </ul>
                </li>
                <li><span class="field-label">Périmètre</span> &mdash; Sélectionnez ou décrivez le périmètre organisationnel. Cela définit la partie de votre organisation qui est évaluée (par ex., « Tous les systèmes informatiques » ou « Infrastructure cloud »).</li>
                <li><span class="field-label">Auditeur principal</span> &mdash; Sélectionnez la personne qui dirige cette évaluation. Le menu déroulant n'affiche que les utilisateurs appartenant aux groupes Administrateur ou Cyber GRC.</li>
                <li><span class="field-label">Date de début prévue</span> &mdash; Quand vous prévoyez de commencer l'évaluation.</li>
                <li><span class="field-label">Date de fin prévue</span> &mdash; Votre date de finalisation cible.</li>
            </ul>
        </li>
        <li>Cliquez sur <span class="btn-label">Créer l'évaluation</span>.</li>
        <li>Votre nouvelle évaluation est créée avec le statut <span class="status-label">Brouillon</span>. Vous pouvez maintenant commencer à répondre aux questions.</li>
    </ol>

    <div class="example-box">
        <strong>Exemple :</strong> Vous réalisez la première revue annuelle de sécurité de votre organisation.<br><br>
        &bull; Titre : <code>2026 Annual Security Assessment</code><br>
        &bull; Type : <code>Initiale</code><br>
        &bull; Périmètre : <code>Tous les systèmes informatiques de l'entreprise</code><br>
        &bull; Auditeur principal : <code>Jane Smith</code><br>
        &bull; Date de début : <code>1er mars 2026</code><br>
        &bull; Date de fin : <code>30 avril 2026</code>
    </div>

    <h3>Statuts d'évaluation</h3>
    <table class="doc-table">
        <tr><th>Statut</th><th>Signification</th></tr>
        <tr><td><span class="status-label">Brouillon</span></td><td>L'évaluation a été créée mais le travail n'a pas encore commencé. Les questions peuvent être répondues.</td></tr>
        <tr><td><span class="status-label">En cours</span></td><td>Évaluation active &mdash; les membres de l'équipe répondent aux questions et importent des preuves.</td></tr>
        <tr><td><span class="status-label">En revue</span></td><td>Toutes les questions ont reçu une réponse &mdash; un auditeur principal ou un validateur examine les réponses.</td></tr>
        <tr><td><span class="status-label">Terminée</span></td><td>L'évaluation est achevée et finalisée. Les réponses sont verrouillées.</td></tr>
        <tr><td><span class="status-label">Archivée</span></td><td>Évaluation historique conservée pour les archives. Plus active.</td></tr>
    </table>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment.png" alt="Assessment list on the Assessment Questionnaire page" loading="lazy">
        <figcaption><strong>Module GRC &rarr; Questionnaire d'évaluation.</strong> Chaque évaluation est listée avec sa référence, son titre, son type, son statut, l'auditeur principal, le score CSF actuel et le pourcentage de conformité, ainsi que la date prévue. Utilisez <span class="btn-label">+ Nouvelle évaluation</span> pour en commencer une, ou <span class="btn-label">Ouvrir</span> pour continuer à répondre à une évaluation existante. Les onglets de statut en haut filtrent la liste.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step2">
    <h2>Étape 2 : Répondre aux questions d'évaluation</h2>
    <p>Une fois que vous avez créé une évaluation, vous devez répondre aux 146 questions de sécurité unifiées. Chaque question appartient à l'un des 14 domaines de sécurité.</p>

    <h3>Les 14 domaines de sécurité</h3>
    <table class="doc-table">
        <tr><th>Code</th><th>Nom du domaine</th><th>Questions</th><th>Ce qu'il couvre</th></tr>
        <tr><td><code>GOV</code></td><td>Gouvernance &amp; Leadership</td><td>12</td><td>Direction du programme de sécurité, stratégie, budget, reporting au conseil</td></tr>
        <tr><td><code>IAM</code></td><td>Gestion des identités &amp; des accès</td><td>14</td><td>Comptes utilisateurs, authentification, contrôles d'accès, accès privilégiés</td></tr>
        <tr><td><code>DSP</code></td><td>Sécurité des données &amp; Confidentialité</td><td>12</td><td>Classification des données, chiffrement, confidentialité, prévention des pertes de données</td></tr>
        <tr><td><code>EPS</code></td><td>Sécurité des terminaux &amp; des plateformes</td><td>10</td><td>Ordinateurs portables, serveurs, appareils mobiles, mises à jour, EDR</td></tr>
        <tr><td><code>NET</code></td><td>Sécurité réseau</td><td>11</td><td>Pare-feu, segmentation, VPN, sécurité DNS, Wi-Fi</td></tr>
        <tr><td><code>APS</code></td><td>Sécurité des applications</td><td>10</td><td>Développement sécurisé, revues de code, sécurité des API, WAF</td></tr>
        <tr><td><code>OPS</code></td><td>Opérations de sécurité</td><td>12</td><td>SIEM, journalisation, surveillance, analyse des vulnérabilités, SOC</td></tr>
        <tr><td><code>INC</code></td><td>Gestion des incidents</td><td>10</td><td>Plans de réponse aux incidents, exercices de simulation, notification de violation</td></tr>
        <tr><td><code>SCM</code></td><td>Chaîne d'approvisionnement &amp; Tiers</td><td>10</td><td>Gestion des fournisseurs, risque de la chaîne d'approvisionnement, contrats</td></tr>
        <tr><td><code>PHY</code></td><td>Physique &amp; Environnemental</td><td>8</td><td>Centres de données, badges d'accès, vidéosurveillance, contrôles environnementaux</td></tr>
        <tr><td><code>HRS</code></td><td>Sécurité des ressources humaines</td><td>10</td><td>Vérifications des antécédents, formation à la sécurité, procédures de départ</td></tr>
        <tr><td><code>BCP</code></td><td>Continuité des activités</td><td>10</td><td>Sauvegarde, reprise après sinistre, tests du PCA, RTO/RPO</td></tr>
        <tr><td><code>CRY</code></td><td>Cryptographie &amp; Gestion des clés</td><td>8</td><td>Normes de chiffrement, rotation des clés, gestion des certificats</td></tr>
        <tr><td><code>CMP</code></td><td>Conformité &amp; Assurance</td><td>9</td><td>Conformité réglementaire, audit interne, préparation à l'audit externe</td></tr>
    </table>

    <h3>Comment répondre aux questions</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Évaluation &amp; Audit</span> &rarr; <span class="menu-label">Questionnaire d'évaluation</span>.</li>
        <li>Si vous avez plusieurs évaluations, sélectionnez la bonne dans le menu déroulant en haut de la page.</li>
        <li>Vous verrez les 14 domaines de sécurité listés. Cliquez sur un nom de domaine (par ex., <strong>GOV - Gouvernance &amp; Leadership</strong>) pour le développer et voir ses questions.</li>
        <li>Pour chaque question, vous devez fournir deux informations :
            <ul>
                <li><span class="field-label">Niveau de maturité</span> (1-4) &mdash; Quel est le niveau de maturité de la mise en œuvre de ce contrôle par votre organisation ?
                    <ul>
                        <li><strong>1 &mdash; Initial/Ad hoc :</strong> Aucun processus formel. Réalisé de manière incohérente ou pas du tout.</li>
                        <li><strong>2 &mdash; En développement :</strong> Certains processus existent mais ne sont pas suivis de manière cohérente. Partiellement documenté.</li>
                        <li><strong>3 &mdash; Défini :</strong> Des processus formels et documentés sont en place et suivis de manière cohérente.</li>
                        <li><strong>4 &mdash; Géré/Optimisé :</strong> Les processus sont mesurés, surveillés et continuellement améliorés.</li>
                    </ul>
                </li>
                <li><span class="field-label">Statut de conformité</span> &mdash; Votre statut de conformité pour cette question :
                    <ul>
                        <li><strong>Conforme</strong> &mdash; Entièrement mis en œuvre et satisfait à l'exigence</li>
                        <li><strong>Partiel</strong> &mdash; Partiellement mis en œuvre ; des lacunes subsistent</li>
                        <li><strong>Non conforme</strong> &mdash; Non mis en œuvre ou ne satisfait pas à l'exigence</li>
                        <li><strong>Non applicable</strong> &mdash; Cette question ne s'applique pas à votre organisation</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li>Facultativement, ajoutez des <span class="field-label">Notes</span> pour expliquer votre réponse. Ceci est fortement recommandé &mdash; les auditeurs voudront voir votre raisonnement.</li>
        <li>Vos réponses sont <strong>sauvegardées automatiquement</strong> au fur et à mesure. Vous n'avez pas besoin de cliquer sur un bouton de sauvegarde.</li>
        <li>Continuez à répondre aux questions dans les 14 domaines. Vous n'avez pas besoin de tout terminer en une seule session &mdash; revenez à tout moment pour reprendre.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Conseil &mdash; La maturité conduit à la conformité :</strong> Lorsque vous définissez un niveau de maturité, le système peut automatiquement déduire le statut de conformité : Maturité 3-4 = Conforme, Maturité 2 = Partiel, Maturité 1 = Non conforme. Vous pouvez le remplacer si nécessaire.
    </div>

    <div class="callout callout-warning">
        <strong>Important :</strong> Chaque question à laquelle vous répondez est mappée aux exigences de plusieurs référentiels. Par exemple, répondre à une question sur l'« Authentification multifacteur » (dans le domaine IAM) met à jour simultanément vos scores de conformité pour SOC 2, ISO 27001, PCI DSS, NIST CSF et CMMC. Vous n'avez jamais besoin de répondre deux fois au même concept.
    </div>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment-questions.png" alt="Answering questions in the assessment questionnaire" loading="lazy">
        <figcaption><strong>Répondre au questionnaire.</strong> L'en-tête suit la <em>Progression</em>, la <em>Maturité CSF</em> en direct et la <em>Conformité</em> au fur et à mesure. Les onglets de domaine (GOV, IAM, DSP, &hellip;) affichent chacun le score actuel de ce domaine ; cliquez sur l'un d'eux pour accéder à ses questions. Pour chaque question, vous définissez un niveau de <strong>Maturité</strong> (1&ndash;4 ou N/A) et un statut de <strong>Conformité</strong> &mdash; les réponses sont sauvegardées automatiquement. Utilisez <span class="btn-label">Afficher les questions sans réponse</span> pour trouver ce qui reste.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step3">
    <h2>Étape 3 : Importer des preuves</h2>
    <p>Les preuves démontrent que vos réponses sont exactes. Les auditeurs s'attendent à voir des preuves pour chaque affirmation de conformité. Les preuves peuvent inclure des captures d'écran, des exports de configuration, des documents de politique, des journaux d'audit, des certificats, et plus encore.</p>

    <h3>Comment importer des preuves lors d'une évaluation</h3>
    <ol class="steps">
        <li>Lors de la réponse à une question dans le <span class="menu-label">Questionnaire d'évaluation</span>, recherchez la section <strong>Preuves</strong> sous la zone de réponse à la question.</li>
        <li>Cliquez sur <span class="btn-label">Importer une preuve</span> ou sur l'icône de pièce jointe.</li>
        <li>Sélectionnez un fichier depuis votre ordinateur. Les types pris en charge incluent PDF, images (PNG, JPG), documents Word, feuilles de calcul Excel et fichiers texte.</li>
        <li>Donnez à la preuve un <span class="field-label">Titre</span> descriptif (par ex., « Capture d'écran de configuration MFA - Console Admin Okta »).</li>
        <li>La preuve est automatiquement liée à la question d'évaluation en cours.</li>
        <li>Vous pouvez importer plusieurs fichiers de preuves par question.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Sécurité :</strong> Tous les fichiers de preuves importés sont chiffrés (AES-256-CBC) avant d'être stockés dans la base de données. Lorsque vous téléchargez des preuves, elles sont déchiffrées à la volée. Cela garantit que les documents de conformité sensibles sont protégés au repos.
    </div>

    <h3>Bibliothèque de preuves</h3>
    <p>Vous pouvez également gérer les preuves séparément via <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Preuves &amp; Surveillance</span> &rarr; <span class="menu-label">Bibliothèque de preuves</span>. Cette page affiche toutes les preuves de toutes les évaluations et contrôles, avec filtrage par type, statut et date d'expiration.</p>
</div>


<div class="doc-section" id="grc-step4">
    <h2>Étape 4 : Consulter vos scores de conformité</h2>
    <p>Au fur et à mesure que vous répondez aux questions, la plateforme calcule en temps réel votre pourcentage de conformité pour chaque référentiel.</p>

    <h3>Consulter les scores sur la page Référentiels</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Conformité</span> &rarr; <span class="menu-label">Référentiels</span>.</li>
        <li>En haut de la page, vous verrez un menu déroulant <span class="field-label">Évaluation</span>. Sélectionnez l'évaluation pour laquelle vous souhaitez voir les scores. Par défaut, l'évaluation la plus récente est sélectionnée.</li>
        <li>En dessous du menu déroulant, vous verrez des cartes de référentiels &mdash; une pour chaque référentiel de conformité auquel des questions sont mappées. Chaque carte affiche :
            <ul>
                <li>Un <strong>graphique en anneau</strong> montrant le pourcentage de conformité global (par ex., 75 %)</li>
                <li>Le <strong>code et le nom du référentiel</strong> (par ex., « SOC2 &mdash; SOC 2 Type II »)</li>
                <li>Le score de <strong>maturité moyenne</strong> (si des données de maturité existent, affiché par ex. sous la forme « 3,50 / 4,00 »)</li>
                <li>Compteurs de métriques : <strong>Conforme</strong>, <strong>Partiel</strong>, <strong>Non conforme</strong> et <strong>Total mappé</strong></li>
            </ul>
        </li>
        <li>Cliquez sur n'importe quelle carte de référentiel pour ouvrir le <strong>Rapport de conformité</strong> détaillé pour ce référentiel.</li>
    </ol>

    <h3>Calcul du pourcentage de conformité</h3>
    <p>Le pourcentage de conformité est calculé comme suit :</p>
    <div class="example-box">
        <strong>Formule :</strong> <code>(Conforme + Partiel &times; 0,5) &divide; Exigences applicables &times; 100</code><br><br>
        &bull; Les exigences <strong>Conformes</strong> comptent pour 100 % d'accomplissement<br>
        &bull; Les exigences <strong>Partielles</strong> comptent pour 50 % d'accomplissement<br>
        &bull; Les exigences <strong>Non applicables</strong> sont exclues du calcul<br>
        &bull; Les exigences <strong>Non conformes</strong> et <strong>Non évaluées</strong> comptent pour 0 %
    </div>

    <h3>Référentiels actuellement pris en charge</h3>
    <table class="doc-table">
        <tr><th>Référentiel</th><th>Version</th><th>Questions mappées</th></tr>
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
    <h2>Étape 5 : Générer un rapport de conformité par référentiel</h2>
    <p>Une fois que vous avez répondu aux questions, vous pouvez générer un rapport de conformité détaillé pour n'importe quel référentiel. Ce rapport est adapté au partage avec des auditeurs, des régulateurs ou la direction.</p>

    <h3>Comment générer un rapport</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Conformité</span> &rarr; <span class="menu-label">Référentiels</span>.</li>
        <li>Sélectionnez votre évaluation dans le menu déroulant <span class="field-label">Évaluation</span> en haut.</li>
        <li>Cliquez sur la carte du référentiel sur lequel vous souhaitez faire un rapport (par ex., « SOC2 &mdash; SOC 2 Type II »).</li>
        <li>La page <strong>Rapport de conformité par référentiel</strong> s'ouvre et affiche :
            <ul>
                <li><strong>En-tête du rapport</strong> &mdash; Nom du référentiel, titre de l'évaluation, type, statut, périmètre, auditeur principal, dates et pourcentage de conformité global</li>
                <li><strong>Statistiques récapitulatives</strong> &mdash; Cartes cliquables affichant le total des exigences, les compteurs Conforme, Partiel, Non conforme, Non évalué et N/A</li>
                <li><strong>Cartes d'exigences</strong> &mdash; Une carte par exigence du référentiel, affichant la référence de l'exigence, le titre, le badge de statut et toutes les questions mappées avec leurs réponses</li>
            </ul>
        </li>
        <li>Pour <strong>filtrer les exigences par statut</strong>, cliquez sur l'une des cartes de statistiques récapitulatives en haut. Par exemple, cliquez sur <strong>Non conforme</strong> pour n'afficher que les exigences non conformes. Cliquez à nouveau dessus (ou cliquez sur « Total des exigences ») pour tout afficher.</li>
        <li>Pour <strong>imprimer le rapport</strong>, cliquez sur le bouton <span class="btn-label">Imprimer le rapport</span> en haut. La boîte de dialogue d'impression de votre navigateur s'ouvrira. Vous pouvez imprimer sur papier ou sélectionner « Enregistrer au format PDF » pour créer un fichier PDF.</li>
    </ol>

    <h3>Ce que chaque carte d'exigence affiche</h3>
    <p>Pour chaque exigence dans le rapport, vous verrez :</p>
    <ul>
        <li><strong>Référence de l'exigence</strong> &mdash; Le numéro de référence officiel (par ex., « CC6.1 » pour SOC 2)</li>
        <li><strong>Titre de l'exigence</strong> &mdash; Ce que dit l'exigence</li>
        <li><strong>Badge de statut</strong> &mdash; Code couleur : vert (Conforme), ambre (Partiel), rouge (Non conforme), gris (Non évalué / N/A)</li>
        <li><strong>Questions mappées</strong> &mdash; Chaque question mappée à cette exigence, affichant :
            <ul>
                <li>Référence et texte de la question</li>
                <li>Niveau de maturité (1-4) avec une barre visuelle</li>
                <li>Statut de conformité</li>
                <li>Statut de validation (En attente, Validé, Rejeté, Nécessite une révision)</li>
                <li>Nom de l'évaluateur et date</li>
                <li>Force du mapping (Exact, Fort, Partiel, Connexe)</li>
                <li>Notes de l'évaluateur</li>
                <li>Notes de validation</li>
                <li>Pièces jointes de preuves (avec liens de téléchargement)</li>
            </ul>
        </li>
    </ul>
</div>


<div class="doc-section" id="grc-fairscore">
    <h2>Tableau de bord du score de maturité CSF</h2>
    <p>La page <strong>Score de maturité CSF</strong> fournit un tableau de bord visuel montrant la maturité de votre organisation dans les 14 domaines de sécurité, aligné sur le NIST Cybersecurity Framework.</p>

    <h3>Comment y accéder</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Évaluation &amp; Audit</span> &rarr; <span class="menu-label">Score de maturité CSF</span>.</li>
        <li>Si vous avez plusieurs évaluations, sélectionnez celle souhaitée dans le menu déroulant.</li>
        <li>La page affiche :
            <ul>
                <li><strong>Score FAIR global</strong> &mdash; Un score de maturité moyen pondéré sur tous les domaines</li>
                <li><strong>Graphique radar</strong> &mdash; Un graphique araignée/radar visuel représentant vos scores sur les 14 domaines</li>
                <li><strong>Cartes de score par domaine</strong> &mdash; Des cartes individuelles pour chaque domaine montrant la maturité moyenne, les questions répondues et la répartition de la conformité</li>
                <li><strong>Barres de conformité par référentiel</strong> &mdash; Des barres horizontales montrant les pourcentages de conformité par référentiel</li>
                <li><strong>Résumé de l'analyse des écarts</strong> &mdash; Les domaines dont les scores sont inférieurs à l'objectif</li>
            </ul>
        </li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grc-fairscore.png" alt="CSF Maturity Score dashboard with radar chart" loading="lazy">
        <figcaption><strong>Module GRC &rarr; Score de maturité CSF.</strong> Les quatre tuiles principales &mdash; <em>Score de maturité CSF</em> (échelle 1&ndash;4), <em>Taux de conformité</em>, <em>Questions répondues</em> et <em>Écarts trouvés</em> &mdash; résument votre posture en un coup d'œil. Le <strong>Radar de maturité des domaines de sécurité</strong> représente les 14 domaines, et la liste à droite donne le score moyen exact de chaque domaine. Choisissez l'évaluation souhaitée dans le menu déroulant en haut.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-gaps">
    <h2>Analyse des écarts</h2>
    <p>La page <strong>Analyse des écarts</strong> regroupe toutes les faiblesses identifiées lors d'une évaluation &mdash; toutes les questions répondues <strong>Non conforme</strong> ou <strong>Partiel</strong> &mdash; en une seule liste de travail priorisée. Elle répond à la question « où sommes-nous en retard, et qu'affecte chaque lacune ? »</p>

    <h3>Comment y accéder</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Évaluation &amp; Audit</span> &rarr; <span class="menu-label">Écarts</span>.</li>
        <li>Sélectionnez l'évaluation que vous souhaitez analyser dans le menu déroulant <span class="field-label">Évaluation</span>.</li>
    </ol>

    <h3>Ce que la page affiche</h3>
    <p>Quatre tuiles récapitulatives en haut comptent vos <strong>Écarts totaux</strong>, <strong>Non conformes</strong>, <strong>Partiels</strong> et les écarts <strong>Avec risque associé</strong>. En dessous, chaque écart est listé sous forme de ligne avec :</p>
    <ul>
        <li><strong>Gravité</strong> &mdash; un badge : <em>Non conforme</em> (rouge) ou <em>Partiel</em> (ambre).</li>
        <li><strong>Domaine</strong> et <strong>Réf.</strong> &mdash; le domaine de sécurité et la référence exacte de la question (par ex., <code>GOV-08</code>).</li>
        <li><strong>Constatation</strong> &mdash; le texte de la question décrivant ce qui manque.</li>
        <li><strong>Impact sur les référentiels</strong> &mdash; des badges pour chaque exigence de référentiel affectée par cet écart, afin que vous puissiez voir d'un coup d'œil si une seule correction améliore simultanément SOC 2, ISO 27001, PCI DSS, et plus encore.</li>
        <li><strong>Risque</strong> &mdash; si un risque a été enregistré pour cet écart, et une action <span class="btn-label">Voir</span> pour ouvrir le détail complet.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/grc-gaps.png" alt="Gap Analysis page listing non-conforming and partial findings" loading="lazy">
        <figcaption><strong>Module GRC &rarr; Écarts.</strong> Chaque réponse non conforme ou partielle devient un écart. La colonne <strong>Impact sur les référentiels</strong> montre quelles exigences de chaque référentiel l'écart touche &mdash; combler un écart peut améliorer plusieurs référentiels en même temps.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-frameworks">
    <h2>Page Référentiels</h2>
    <p>La page <strong>Référentiels</strong> est votre centre névralgique pour voir le statut de conformité sur tous les référentiels pris en charge. Elle affiche les données de conformité issues des évaluations.</p>

    <h3>Comment l'utiliser</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Conformité</span> &rarr; <span class="menu-label">Référentiels</span>.</li>
        <li>Sélectionnez une évaluation dans le menu déroulant <span class="field-label">Évaluation</span>. La page affiche par défaut votre évaluation la plus récente.</li>
        <li>La page affiche les cartes de référentiels en grille. Seuls les référentiels avec des questions mappées apparaissent. Chaque carte affiche le pourcentage de conformité, le score de maturité et les compteurs de métriques.</li>
        <li>Cliquez sur une carte de référentiel pour ouvrir le rapport de conformité détaillé.</li>
    </ol>

    <figure class="doc-figure">
        <img src="app/docs/grc-frameworks.png" alt="Compliance Frameworks page with per-framework compliance cards" loading="lazy">
        <figcaption><strong>Module GRC &rarr; Référentiels.</strong> Les tuiles du haut comptent vos référentiels, la préparation moyenne, le total des exigences et combien <em>nécessitent attention</em>. Chaque carte affiche l'anneau de conformité d'un référentiel, sa maturité moyenne et la répartition Conforme / Partiel / Non conforme / Total mappé. Cliquez sur n'importe quelle carte pour ouvrir le rapport de conformité complet de ce référentiel.</figcaption>
    </figure>

    <h3>Arborescence des exigences du référentiel</h3>
    <p>Si vous accédez à cette page <em>sans</em> sélectionner d'évaluation (ou en cliquant sur un lien de référentiel depuis ailleurs), vous verrez la vue <strong>Arborescence des exigences</strong>. Elle affiche la structure hiérarchique de toutes les exigences au sein d'un référentiel, ainsi que les contrôles mappés et le statut de mise en œuvre. Les administrateurs et les utilisateurs Cyber GRC peuvent ajouter, modifier et supprimer des exigences personnalisées ici.</p>
</div>


<div class="doc-section" id="grc-controls">
    <h2>Contrôles internes</h2>
    <p>Les <strong>Contrôles internes</strong> sont les mesures de sécurité spécifiques que votre organisation a mises en place. Exemples : « Authentification multifacteur sur tous les systèmes », « Sauvegardes chiffrées quotidiennes », « Tests de pénétration annuels ».</p>

    <h3>Comment créer un contrôle</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Conformité</span> &rarr; <span class="menu-label">Contrôles internes</span>.</li>
        <li>Cliquez sur <span class="btn-label">+ Nouveau contrôle</span>.</li>
        <li>Remplissez les champs :
            <ul>
                <li><span class="field-label">Titre du contrôle</span> &mdash; Un nom court (par ex., « MFA pour tous les comptes utilisateurs »)</li>
                <li><span class="field-label">Description</span> &mdash; Description détaillée de ce que fait ce contrôle</li>
                <li><span class="field-label">Type de contrôle</span> &mdash; Préventif, Détectif, Correctif ou Directif</li>
                <li><span class="field-label">Catégorie</span> &mdash; Technique, Administratif ou Physique</li>
                <li><span class="field-label">Statut de mise en œuvre</span> &mdash; Planifié, En cours, Mis en œuvre ou Non applicable</li>
                <li><span class="field-label">Efficacité</span> &mdash; Non testé, Inefficace, Partiellement efficace ou Efficace</li>
                <li><span class="field-label">Niveau de risque</span> &mdash; Faible, Moyen, Élevé ou Critique</li>
                <li><span class="field-label">Responsable</span> &mdash; La personne responsable (limitée aux membres des groupes Administrateur et Cyber GRC)</li>
                <li><span class="field-label">Fréquence de test</span> &mdash; À quelle fréquence ce contrôle est testé (Quotidien, Hebdomadaire, Mensuel, etc.)</li>
            </ul>
        </li>
        <li>Sous <strong>Mapping aux référentiels</strong>, sélectionnez les exigences de référentiel que ce contrôle satisfait. Vous pouvez mapper un contrôle à des exigences de plusieurs référentiels.</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer</span>.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Avantage clé &mdash; Mapping multi-référentiels :</strong> Un seul contrôle comme « MFA » peut satisfaire simultanément les exigences de SOC 2 (CC6.1), ISO 27001 (A.8.5), PCI DSS (8.4.2) et NIST CSF (PR.AC-7). Mappez-le une fois et il couvre tous les référentiels.
    </div>
</div>


<div class="doc-section" id="grc-crosswalk">
    <h2>Correspondance des référentiels</h2>
    <p>La <strong>Correspondance des référentiels</strong> montre comment la conformité avec un référentiel fournit automatiquement une couverture pour un autre. Par exemple, si vous êtes conforme SOC 2, quelle partie d'ISO 27001 couvrez-vous déjà ?</p>

    <h3>Comment l'utiliser</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Conformité</span> &rarr; <span class="menu-label">Correspondance des référentiels</span>.</li>
        <li>Sélectionnez un <span class="field-label">Référentiel source</span> (le référentiel que vous avez déjà complété, par ex., « SOC 2 »).</li>
        <li>Sélectionnez un <span class="field-label">Référentiel cible</span> (le référentiel auquel vous souhaitez vous comparer, par ex., « ISO 27001 »).</li>
        <li>Le tableau de correspondance montre quelles exigences cibles sont couvertes par vos contrôles sources, et lesquelles présentent des lacunes.</li>
    </ol>
</div>


<div class="doc-section" id="grc-evidence">
    <h2>Bibliothèque de preuves</h2>
    <p>La <strong>Bibliothèque de preuves</strong> est un référentiel centralisé pour toutes les preuves de conformité de votre organisation.</p>

    <h3>Comment importer des preuves</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Preuves &amp; Surveillance</span> &rarr; <span class="menu-label">Bibliothèque de preuves</span>.</li>
        <li>Cliquez sur <span class="btn-label">+ Importer une preuve</span>.</li>
        <li>Remplissez : <span class="field-label">Titre</span>, <span class="field-label">Type de preuve</span> (capture d'écran, document, certificat, configuration, rapport, etc.), <span class="field-label">Description</span> et optionnellement une <span class="field-label">Date d'expiration</span>.</li>
        <li>Sélectionnez le fichier à importer.</li>
        <li>Cliquez sur <span class="btn-label">Importer</span>. Le fichier est chiffré et stocké de manière sécurisée.</li>
        <li>Vous pouvez ensuite lier cette preuve à des contrôles spécifiques ou des réponses d'évaluation.</li>
    </ol>

    <h3>Statuts des preuves</h3>
    <table class="doc-table">
        <tr><th>Statut</th><th>Signification</th></tr>
        <tr><td><strong>Actuelle</strong></td><td>Preuve active et valide</td></tr>
        <tr><td><strong>Expirée</strong></td><td>Passé sa date d'expiration &mdash; doit être renouvelée</td></tr>
        <tr><td><strong>Remplacée</strong></td><td>Remplacée par une preuve plus récente</td></tr>
        <tr><td><strong>Brouillon</strong></td><td>Importée mais pas encore examinée ou finalisée</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-policies">
    <h2>Gestion des politiques</h2>
    <p>La page <strong>Politiques</strong> fournit un cycle de vie complet des politiques &mdash; de la rédaction à l'approbation, à la publication et à la révision périodique.</p>

    <h3>Comment créer une politique</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Gestion des politiques</span> &rarr; <span class="menu-label">Politiques</span>.</li>
        <li>Cliquez sur <span class="btn-label">+ Nouvelle politique</span>.</li>
        <li>Remplissez : <span class="field-label">Titre</span>, <span class="field-label">Catégorie</span> (Sécurité, Confidentialité, Conformité, Opérationnel, RH, Informatique, etc.), <span class="field-label">Fréquence de révision</span> (à quelle fréquence la politique doit être révisée).</li>
        <li>Rédigez le contenu de la politique à l'aide de l'éditeur de texte enrichi.</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer</span>. La politique est créée avec le statut <span class="status-label">Brouillon</span>.</li>
        <li>Lorsque vous êtes prêt, soumettez pour <strong>Révision</strong> &rarr; <strong>Approbation</strong> &rarr; <strong>Publication</strong>.</li>
    </ol>

    <h3>Cycle de vie d'une politique</h3>
    <p><span class="status-label">Brouillon</span> &rarr; <span class="status-label">Révision</span> &rarr; <span class="status-label">Approuvée</span> &rarr; <span class="status-label">Publiée</span> &rarr; (Révision périodique ou <span class="status-label">Retirée</span>)</p>
</div>


<div class="doc-section" id="grc-audits">
    <h2>Audits &amp; Constatations</h2>
    <p>La page <strong>Audits</strong> gère le cycle de vie complet de l'audit &mdash; de la planification aux travaux sur le terrain, aux constatations, à la remédiation et à la clôture.</p>

    <h3>Comment créer un audit</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Évaluation &amp; Audit</span> &rarr; <span class="menu-label">Audits</span>.</li>
        <li>Cliquez sur <span class="btn-label">+ Nouvel audit</span>.</li>
        <li>Remplissez : <span class="field-label">Titre</span>, <span class="field-label">Type d'audit</span> (Interne, Externe, Certification, Surveillance, Préparation), <span class="field-label">Référentiel</span>, <span class="field-label">Auditeur principal</span>, <span class="field-label">Dates de début/fin prévues</span>.</li>
        <li>Cliquez sur <span class="btn-label">Créer</span>.</li>
    </ol>

    <h3>Enregistrement des constatations</h3>
    <ol class="steps">
        <li>Ouvrez un audit et cliquez sur <span class="btn-label">+ Ajouter une constatation</span>.</li>
        <li>Remplissez : <span class="field-label">Titre</span>, <span class="field-label">Gravité</span> (Informationnel, Faible, Moyen, Élevé, Critique), <span class="field-label">Type de constatation</span> (Non-conformité, Observation, Opportunité, Point fort) et <span class="field-label">Description</span>.</li>
        <li>Mappez la constatation à des exigences de référentiel ou des contrôles spécifiques.</li>
        <li>Assignez la remédiation à un membre de l'équipe avec une date d'échéance.</li>
        <li>Suivez la progression de la remédiation jusqu'au statut <strong>Clôturé et vérifié</strong>.</li>
    </ol>

    <h3>Statuts d'audit</h3>
    <table class="doc-table">
        <tr><th>Statut</th><th>Signification</th></tr>
        <tr><td><strong>Planification</strong></td><td>Définition du périmètre, des objectifs et du calendrier</td></tr>
        <tr><td><strong>Travaux sur le terrain</strong></td><td>Tests actifs, examen des preuves et entretiens</td></tr>
        <tr><td><strong>Rédaction du rapport</strong></td><td>Rédaction du rapport d'audit et documentation des constatations</td></tr>
        <tr><td><strong>Remédiation</strong></td><td>Les constatations ont été rapportées ; l'équipe corrige les problèmes</td></tr>
        <tr><td><strong>Clôturé</strong></td><td>Toutes les constatations résolues et l'audit est complet</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-risks">
    <h2>Registre des risques</h2>
    <p>Le <strong>Registre des risques</strong> suit les risques organisationnels avec une notation de probabilité/impact, des plans de traitement et des liens vers les contrôles.</p>

    <h3>Comment ajouter un risque</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Évaluation &amp; Audit</span> &rarr; <span class="menu-label">Registre des risques</span>.</li>
        <li>Cliquez sur <span class="btn-label">+ Nouveau risque</span>.</li>
        <li>Remplissez : <span class="field-label">Titre</span>, <span class="field-label">Description</span>, <span class="field-label">Catégorie</span> (Stratégique, Opérationnel, Financier, Conformité, Réputation, Technologique, Tiers).</li>
        <li>Définissez la <span class="field-label">Probabilité</span> (Rare, Peu probable, Possible, Probable, Quasi certain) et l'<span class="field-label">Impact</span> (Insignifiant, Mineur, Modéré, Majeur, Catastrophique).</li>
        <li>Le système calcule le <strong>Score de risque inhérent</strong> (Probabilité &times; Impact, sur une échelle de 1 à 25).</li>
        <li>Sélectionnez une <span class="field-label">Stratégie de traitement</span> : Accepter, Atténuer, Transférer ou Éviter.</li>
        <li>Liez les contrôles internes pertinents pour montrer comment le risque est atténué. Le système calcule le <strong>Score de risque résiduel</strong> après les contrôles.</li>
    </ol>
</div>


<div class="doc-section" id="grc-monitors">
    <h2>Moniteurs continus</h2>
    <p>Les <strong>Moniteurs continus</strong> sont des vérifications automatisées qui contrôlent vos contrôles de sécurité selon un calendrier (horaire, quotidien, hebdomadaire ou mensuel).</p>

    <h3>Comment créer un moniteur</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Preuves &amp; Surveillance</span> &rarr; <span class="menu-label">Moniteurs continus</span>.</li>
        <li>Cliquez sur <span class="btn-label">+ Nouveau moniteur</span>.</li>
        <li>Remplissez : <span class="field-label">Titre</span>, <span class="field-label">Type de vérification</span>, <span class="field-label">Fréquence</span> (Horaire, Quotidien, Hebdomadaire, Mensuel) et la <span class="field-label">Configuration du collecteur</span> (paramètres JSON pour la vérification).</li>
        <li>Liez le moniteur à un contrôle interne.</li>
        <li>Activez le moniteur. Il s'exécutera automatiquement selon le calendrier configuré.</li>
        <li>Consultez les résultats (Réussi, Échec, Erreur, Avertissement) et l'historique d'exécution sur la page de détail du moniteur.</li>
    </ol>
</div>


<div class="doc-section" id="grc-tasks">
    <h2>Boîte de réception des tâches</h2>
    <p>La <strong>Boîte de réception des tâches</strong> affiche toutes les tâches GRC qui vous sont assignées dans toutes les évaluations. Les tâches sont créées lors des évaluations pour déléguer des travaux tels que la collecte de preuves, la remédiation, les révisions ou la documentation.</p>

    <h3>Comment l'utiliser</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Évaluation &amp; Audit</span> &rarr; <span class="menu-label">Boîte de réception des tâches</span>.</li>
        <li>Vous verrez une liste de tâches qui vous sont assignées. Chaque tâche affiche : titre, type (Demande de preuve, Remédiation, Révision, Documentation, Mise en œuvre), priorité, date d'échéance et statut.</li>
        <li>Cliquez sur une tâche pour voir les détails et mettre à jour son statut.</li>
        <li>Marquez les tâches comme <span class="status-label">En cours</span> lorsque vous commencez à travailler, et <span class="status-label">Terminées</span> lorsque c'est fait.</li>
    </ol>
</div>


<div class="doc-section" id="grc-dashboard">
    <h2>Tableau de bord GRC</h2>
    <p>Le <strong>Tableau de bord GRC</strong> est votre centre de commande de la conformité &mdash; une vue d'ensemble d'une seule page de l'ensemble de votre posture GRC.</p>

    <h3>Ce que le tableau de bord affiche</h3>
    <ul>
        <li><strong>Carte thermique de conformité par référentiel</strong> &mdash; Pourcentages de conformité codés par couleur pour chaque référentiel</li>
        <li><strong>Progression de la mise en œuvre des contrôles</strong> &mdash; Combien de contrôles sont mis en œuvre par rapport à ce qui est planifié</li>
        <li><strong>Fraîcheur des preuves</strong> &mdash; Combien d'éléments de preuves sont actuels, arrivant à expiration ou expirés</li>
        <li><strong>Constatations ouvertes</strong> &mdash; Nombre et répartition par gravité des constatations d'audit non résolues</li>
        <li><strong>Statut de révision des politiques</strong> &mdash; Politiques devant faire l'objet d'une révision</li>
        <li><strong>Santé des moniteurs</strong> &mdash; Statut réussi/échoué des moniteurs continus</li>
        <li><strong>Résumé du registre des risques</strong> &mdash; Risques ouverts par gravité</li>
    </ul>

    <h3>Comment y accéder</h3>
    <p>Accédez à <span class="menu-label">Module GRC</span> &rarr; <span class="menu-label">Conformité</span> &rarr; <span class="menu-label">Tableau de bord GRC</span>.</p>
</div>


<!-- ================================================================
     TPRM MODULE
     ================================================================ -->
<div class="doc-section" id="tprm-overview">
    <h2>Module TPRM : Qu'est-ce que la gestion des risques tiers ?</h2>
    <p>Chaque entreprise dépend de fournisseurs externes &mdash; prestataires cloud, prestataires de paie, plateformes marketing, consultants informatiques. Chaque fournisseur peut avoir accès à vos données ou systèmes. Le <strong>TPRM</strong> vous aide à répondre à la question : « Quel est le niveau de risque de chaque fournisseur, et protègent-ils nos données ? »</p>
    <ul>
        <li>Ajoutez et suivez tous vos fournisseurs en un seul endroit</li>
        <li>Attribuez un niveau de risque (Niveau 1 = risque le plus élevé, Niveau 3 = le plus faible)</li>
        <li>Envoyez des questionnaires de sécurité (évaluations) aux fournisseurs</li>
        <li>Notez automatiquement les fournisseurs en utilisant des services externes de notation de sécurité</li>
        <li>Réalisez une analyse quantitative des risques (FAIR) pour estimer les pertes financières potentielles</li>
        <li>Suivez les risques de quatrième partie (les fournisseurs de vos fournisseurs)</li>
        <li>Découvrez les applications SaaS non gérées (Shadow SaaS)</li>
    </ul>
</div>

<div class="doc-section" id="tprm-add-vendor">
    <h2>Ajouter un nouveau fournisseur</h2>
    <ol class="steps">
        <li>Dans la barre latérale gauche, développez <span class="menu-label">Module TPRM</span>, puis développez la section <span class="menu-label">Parties prenantes</span>.</li>
        <li>Cliquez sur <span class="menu-label">Nouvelle demande</span>. Cela ouvre le formulaire d'intégration du fournisseur.</li>
        <li>Remplissez les champs obligatoires :
            <ul>
                <li><span class="field-label">Nom du fournisseur</span> &mdash; Le nom légal de l'entreprise (par ex., « Acme Cloud Services »)</li>
                <li><span class="field-label">Domaine du fournisseur</span> &mdash; Leur domaine web sans https:// (par ex., « acmecloud.com »). Utilisé par les moteurs de notation de sécurité pour analyser le fournisseur.</li>
            </ul>
        </li>
        <li>Remplissez les champs optionnels recommandés :
            <ul>
                <li><span class="field-label">Type de fournisseur</span> &mdash; Technologie, Services professionnels, Services financiers, RH/Avantages sociaux, etc.</li>
                <li><span class="field-label">Niveau du fournisseur</span> &mdash; 1 (Critique), 2 (Important) ou 3 (Standard)</li>
                <li><span class="field-label">Nom du contact principal</span>, <span class="field-label">E-mail</span>, <span class="field-label">Téléphone</span></li>
                <li><span class="field-label">Nombre d'enregistrements PII</span> &mdash; Combien d'enregistrements personnels ce fournisseur accède</li>
                <li><span class="field-label">Nombre d'enregistrements SPII</span> &mdash; Combien d'enregistrements personnels sensibles (numéros de sécurité sociale, données de santé)</li>
            </ul>
        </li>
        <li>Cliquez sur <span class="btn-label">Enregistrer</span>. Le fournisseur est créé avec le statut <strong>Brouillon</strong>.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Explication des niveaux de fournisseurs :</strong><br>
        &bull; <strong>Niveau 1 (Critique)</strong> &mdash; Fournisseurs ayant accès à des données sensibles ou à des systèmes critiques. Requièrent une évaluation complète.<br>
        &bull; <strong>Niveau 2 (Important)</strong> &mdash; Fournisseurs avec un accès modéré. Requièrent une évaluation standard.<br>
        &bull; <strong>Niveau 3 (Standard)</strong> &mdash; Fournisseurs à faible risque. Peuvent nécessiter uniquement une révision de base.
    </div>
</div>

<div class="doc-section" id="tprm-lifecycle">
    <h2>Cycle de vie du fournisseur</h2>
    <p>Les fournisseurs passent par un cycle de vie défini :</p>
    <p><span class="status-label">Brouillon</span> &rarr; <span class="status-label">En attente de révision</span> &rarr; <span class="status-label">En cours de révision</span> &rarr; <span class="status-label">Approuvé</span> (ou <span class="status-label">Rejeté</span>) &rarr; <span class="status-label">Actif</span> &rarr; <span class="status-label">Révision annuelle</span> &rarr; <span class="status-label">Désintégré</span></p>
    <p>Chaque étape déclenche des flux de travail, des notifications et des actions requises appropriés.</p>
</div>

<div class="doc-section" id="tprm-assessments">
    <h2>Évaluations des fournisseurs</h2>
    <p>Les évaluations des fournisseurs sont des questionnaires de sécurité envoyés aux fournisseurs pour évaluer leur posture de sécurité. Accédez à <span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Évaluations des fournisseurs</span> pour les gérer.</p>
    <ol class="steps">
        <li>Ouvrez la page de détail d'un fournisseur.</li>
        <li>Cliquez sur <span class="btn-label">Envoyer une évaluation</span>.</li>
        <li>Sélectionnez le modèle d'évaluation adapté au niveau du fournisseur.</li>
        <li>Le fournisseur reçoit un e-mail avec un lien pour compléter le questionnaire.</li>
        <li>Une fois soumis, examinez les réponses du fournisseur et notez-les.</li>
    </ol>
</div>

<div class="doc-section" id="assessment-forms">
    <h2>Formulaires d'évaluation : Télécharger, remplir, importer &amp; Remplissage automatique par IA <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Tous les fournisseurs ne souhaitent pas répondre à un questionnaire dans le navigateur. Depuis la page d'une évaluation individuelle (<span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Évaluations des fournisseurs</span> &rarr; ouvrez une évaluation), vous pouvez remettre au fournisseur une copie hors ligne, récupérer un fichier complété, ou laisser un fournisseur IA préremplir les réponses à partir des propres certificats du fournisseur. Les boutons se trouvent sur une ligne près du haut de l'évaluation.</p>

    <h3>Télécharger l'évaluation sous forme de fichier à remplir</h3>
    <ul>
        <li><span class="btn-label">Télécharger le PDF</span> &mdash; un formulaire PDF à remplir. Chaque question devient un véritable champ de formulaire, de sorte que le fournisseur peut saisir du texte et cocher des cases directement dans le fichier.</li>
        <li><span class="btn-label">Télécharger Excel</span> &mdash; un véritable classeur <code>.xlsx</code> qui peut être complété dans Excel, Google Sheets ou LibreOffice. Les questions à choix unique disposent de listes déroulantes intégrées aux cellules, et les questions conditionnelles se grisent automatiquement lorsqu'elles ne s'appliquent pas.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Le PDF à remplir fonctionne désormais dans n'importe quel navigateur, pas seulement Adobe.</strong> Les cases à cocher intègrent des apparences prégénérées afin de s'afficher et de basculer dans Chrome, Edge et d'autres visionneuses PDF intégrées (auparavant, elles ne fonctionnaient que dans Adobe Acrobat/Reader). Un champ de nom saisi intitulé <strong>&ldquo;SIGNATURE (TYPE FULL NAME)&rdquo;</strong> permet à quiconque de signer dans n'importe quelle visionneuse ; les champs de signature numérique et de date de signature propres à Adobe restent masqués sauf dans Acrobat/Reader, qui peut réellement les utiliser.
    </div>

    <h3>Importer une évaluation complétée (PDF, Excel ou CSV)</h3>
    <p>Lorsque le fournisseur renvoie le fichier terminé, cliquez sur <span class="btn-label">Importer l'évaluation complétée</span> et importez-le. La plateforme détecte le format automatiquement &mdash; un <strong>PDF</strong>, un fichier <strong>Excel (.xlsx)</strong> ou un <strong>CSV</strong> complété &mdash; et fusionne les réponses dans les réponses existantes de l'évaluation.</p>
    <div class="callout callout-warning">
        <strong>La référence du fichier doit correspondre.</strong> Chaque fichier téléchargé porte une <strong>Référence</strong> masquée (l'ID de l'évaluation). Si la référence est manquante ou appartient à une autre évaluation, l'import est refusé et rien n'est écrit &mdash; les réponses ne peuvent donc jamais aboutir sur la mauvaise évaluation.
    </div>

    <h3>Vous avez plutôt un certificat ? <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Un fournisseur qui complète une évaluation peut se voir proposer un raccourci : s'il détient une certification pertinente, il peut l'importer au lieu de répondre à chaque question. L'invite <strong>&ldquo;Avez-vous un certificat ?&rdquo;</strong> affiche désormais les <strong>Instructions d'import de certificat</strong> rédigées par l'auteur du modèle, elle n'est donc plus limitée à ISO 27001 &mdash; un modèle peut inviter un certificat SOC 2 Type 2, ISO 27001 ou tout autre certificat. (Les auteurs de modèles définissent ce texte dans le Générateur de modèles ; voir <a href="#admin-templates">Générateur de modèles d'évaluation</a>.)</p>

    <h3>Remplissage automatique par IA à partir des certifications <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Si votre administrateur a configuré un <a href="#admin-ai">fournisseur IA</a>, un examinateur autorisé peut laisser l'IA lire les documents de certification importés par le fournisseur et préremplir le questionnaire. Cliquez sur <span class="btn-label">&#9889; Remplissage automatique à partir des certifications</span> sur la page d'évaluation.</p>
    <figure class="doc-figure">
        <img src="app/docs/assessment-ai-autofill.png" alt="The Auto-Fill from Certifications button on a vendor assessment" loading="lazy">
        <figcaption><strong>Remplissage automatique à partir des certifications.</strong> Avec un fournisseur IA configuré, le bouton apparaît à côté de <strong>Télécharger le PDF</strong>, <strong>Télécharger Excel</strong> et <strong>Importer l'évaluation complétée</strong>. Il lit les documents de certification actuels du fournisseur et remplit les questions auxquelles ces documents répondent.</figcaption>
    </figure>
    <p>Lorsque vous cliquez dessus, un rappel s'affiche : <em>&ldquo;Cela analysera les documents de certification du fournisseur et préremplira les questions sans réponse. Les réponses existantes ne seront pas modifiées.&rdquo;</em> L'IA parcourt ensuite les certificats du fournisseur et rapporte, par exemple, <em>&ldquo;12 questions sans réponse sur 30 remplies.&rdquo;</em> Quelques points à connaître :</p>
    <ul>
        <li><strong>Seuls les certificats actuels sont utilisés.</strong> Elle lit les documents importés du fournisseur dont le type est <em>Certification</em> et qui sont <strong>actifs et non expirés</strong> (documents PDF, CSV et Excel ; les quelques plus récents). Un certificat expiré ou remplacé est ignoré.</li>
        <li><strong>Elle ne remplit que les champs vides.</strong> Les questions auxquelles vous avez déjà répondu restent intactes, et elle n'écrase jamais une réponse existante.</li>
        <li><strong>Elle ne répond qu'à partir de ce que disent réellement les documents.</strong> L'IA a pour consigne de ne pas deviner ; tout ce qu'elle ne peut pas étayer avec certitude à partir des documents reste sans réponse pour qu'une personne le complète.</li>
        <li><strong>Vous gardez le contrôle.</strong> Les réponses remplies sont enregistrées et la page se recharge en les affichant, de sorte que vous pouvez examiner et modifier n'importe quelle réponse avant que l'évaluation ne soit soumise.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Qui peut l'utiliser, et quand il apparaît.</strong> Le bouton n'est affiché qu'aux <strong>Administrateurs</strong> et aux utilisateurs <strong>Cyber TPRM</strong>, uniquement lorsqu'un fournisseur IA est activé, et uniquement lorsque le fournisseur possède au moins un document de certification actuel au dossier. Il est masqué sur les évaluations terminées.
    </div>
</div>

<div class="doc-section" id="tprm-action-plan">
    <h2>Plan d'action fournisseur <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>L'onglet <strong>Plan d'action</strong> sur la page d'un fournisseur permet à l'équipe cyber de planifier un travail de suivi pour ce fournisseur &mdash; contacter le fournisseur, envoyer une autre évaluation, forcer une révision annuelle &mdash; avec une date d'échéance, des responsables et un ensemble de notes de statut. Une tâche quotidienne déclenche chaque action à l'arrivée de sa date et la transforme en tâche à faire suivie.</p>
    <p>Ouvrez un fournisseur depuis <span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Intégration des fournisseurs</span> &rarr; <span class="menu-label">Mes fournisseurs</span>, puis cliquez sur l'onglet <strong>Plan d'action</strong>. Cet onglet est disponible pour les utilisateurs <strong>Administrateurs</strong> et <strong>Cyber TPRM</strong>.</p>

    <h3>Planifier une action</h3>
    <ol class="steps">
        <li>Dans l'onglet <strong>Plan d'action</strong>, cliquez sur <span class="btn-label">+ Créer une action</span>.</li>
        <li>Choisissez l'<span class="field-label">Action</span> : <strong>Contacter le fournisseur</strong>, <strong>Contacter la partie prenante</strong>, <strong>Envoyer une évaluation</strong> ou <strong>Forcer la révision annuelle</strong>. (Si vous choisissez <strong>Envoyer une évaluation</strong>, un sélecteur <span class="field-label">Évaluation du fournisseur</span> apparaît pour vous permettre de choisir le modèle à envoyer.)</li>
        <li>Définissez la <span class="field-label">Date d'échéance</span> &mdash; le jour où l'action doit se déclencher.</li>
        <li>Sous <span class="field-label">Assigner à (Cyber TPRM)</span>, cochez un ou plusieurs responsables Cyber TPRM. (S'il n'y en a aucun, l'action revient à la partie prenante du fournisseur.)</li>
        <li>Cochez facultativement <span class="field-label">Envoyer un e-mail aux personnes assignées au déclenchement de cette action</span>, et utilisez <span class="field-label">Adresses e-mail de notification</span> pour envoyer plutôt à des adresses spécifiques &mdash; séparées par des virgules. Laissez ce champ vide pour utiliser les adresses e-mail de compte des personnes assignées.</li>
        <li>Rédigez une <span class="field-label">Description</span> (elle est reportée dans la tâche à faire qui est créée), puis cliquez sur <span class="btn-label">Créer une action</span>.</li>
    </ol>

    <h3>Ce qui se passe lorsqu'une action se déclenche</h3>
    <p>Chaque action se déclenche une fois, à sa date d'échéance ou après. Le déclenchement crée une <strong>tâche cyber</strong> liée qui renvoie directement vers cet onglet Plan d'action, exécute l'action (pour <strong>Envoyer une évaluation</strong>, elle envoie le questionnaire par e-mail au fournisseur ; pour <strong>Forcer la révision annuelle</strong>, elle marque la révision annuelle comme due) et &mdash; si vous l'avez activé &mdash; envoie un e-mail aux responsables ou aux adresses que vous avez listées.</p>

    <h3>Statuts des actions</h3>
    <p>Une action passe par ces statuts :</p>
    <p><span class="status-label">En attente</span> &rarr; <span class="status-label">En cours</span> (défini automatiquement lors du déclenchement) &rarr; <span class="status-label">Terminée</span>, ou <span class="status-label">Problème</span> si quelque chose s'est mal passé lors du déclenchement, ou <span class="status-label">Annulée</span> si vous l'annulez avant son déclenchement. Vous pouvez modifier le statut vous-même à tout moment ; la tâche quotidienne n'écrase jamais un statut que vous avez défini.</p>

    <h3>Notes de statut</h3>
    <p>Ouvrez une action pour ajouter des <strong>Notes de statut</strong> datées au fur et à mesure de l'avancement. Tapez une note et cliquez sur <span class="btn-label">Ajouter une note</span>. Vous pouvez modifier ou supprimer vos propres notes ; les administrateurs peuvent modifier ou supprimer celles de n'importe qui. Chaque création, modification et suppression est consignée dans le journal d'audit.</p>

    <div class="callout callout-info">
        <strong>La tâche &ldquo;Calendrier de remédiation des fournisseurs&rdquo;.</strong> La tâche quotidienne qui déclenche les actions dues s'appelle <strong>Calendrier de remédiation des fournisseurs</strong> et s'exécute chaque jour à <strong>7h00</strong> par défaut. Les administrateurs peuvent l'activer, la désactiver ou la reprogrammer sur la page <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Planificateur</span>. Si elle a été désactivée, elle rattrape son retard lors de sa prochaine exécution, en déclenchant tout ce qui est devenu dû entre-temps.
    </div>
</div>

<div class="doc-section" id="tprm-srs">
    <h2>Carte de score des risques de sécurité (SRS)</h2>
    <p>La SRS fournit un score de sécurité externe automatisé pour chaque fournisseur basé sur la configuration DNS, SSL/TLS, la sécurité des e-mails (SPF, DKIM, DMARC), les ports ouverts et d'autres indicateurs techniques.</p>
    <p>Accédez à <span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Carte de score des risques de sécurité</span>.</p>
</div>

<div class="doc-section" id="tprm-fair">
    <h2>Analyse FAIR</h2>
    <p><strong>FAIR</strong> (Factor Analysis of Information Risk) est un modèle de risque quantitatif qui estime la perte financière probable résultant d'un incident de sécurité impliquant un fournisseur.</p>
    <p>Accédez à <span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Analyse FAIR</span> pour créer et consulter des analyses.</p>
</div>

<div class="doc-section" id="tprm-fourth-party">
    <h2>Risque de quatrième partie</h2>
    <p>Suivez les fournisseurs dont <em>vos fournisseurs</em> dépendent. Si votre fournisseur cloud utilise un sous-traitant pour le stockage des données, il s'agit d'un risque de quatrième partie. Depuis la barre latérale, vous pouvez ouvrir <span class="menu-label">Risque de quatrième partie</span> (concentration technologique), <span class="menu-label">Recherche CVE</span> et <span class="menu-label">Sous-traitants</span>. Cette fonctionnalité est disponible pour les administrateurs et les utilisateurs Cyber TPRM ; les auditeurs peuvent consulter mais pas agir.</p>

    <h3>Concentration des sous-traitants <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Ouvrez <span class="menu-label">Sous-traitants</span> pour voir la vue <strong>Concentration des sous-traitants</strong> : chaque sous-traitant que vos fournisseurs ont déclaré, et combien de vos fournisseurs utilisent chacun d'eux. Un sous-traitant partagé par plusieurs fournisseurs est mis en évidence &mdash; cette dépendance partagée constitue un risque de concentration de la chaîne d'approvisionnement. (Les sous-traitants sont ajoutés à un fournisseur depuis la page de détail de ce fournisseur.)</p>

    <h3>Envoyer une évaluation à tous ceux qui utilisent un sous-traitant <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Lorsqu'un sous-traitant concentre le risque, vous pouvez interroger les fournisseurs qui en dépendent en une seule action :</p>
    <ol class="steps">
        <li>Dans la liste des sous-traitants, cliquez sur <span class="btn-label">Envoyer une évaluation</span> sur la ligne de ce sous-traitant.</li>
        <li>Dans le sélecteur de fournisseurs, choisissez lesquels des fournisseurs utilisant ce sous-traitant doivent recevoir l'évaluation (ou <span class="field-label">Sélectionner tous les éléments visibles</span>), puis continuez.</li>
        <li>Choisissez un <span class="field-label">Modèle d'évaluation</span> et une fenêtre <span class="field-label">Expire dans</span> (14, 30, 60 ou 90 jours), puis cliquez sur <span class="btn-label">Attribuer l'évaluation</span>.</li>
    </ol>
    <p>Chaque fournisseur sélectionné reçoit le questionnaire par e-mail (une demande d'informations), et un rappel est suivi afin que les relances soient envoyées automatiquement. Les fournisseurs sans adresse e-mail au dossier sont ignorés, et tout envoi qui échoue est retenté par la tâche de rappel. Le même flux <strong>Attribuer l'évaluation</strong> est disponible depuis les vues de concentration technologique et de CVE.</p>
</div>

<div class="doc-section" id="tprm-shadow-saas">
    <h2>Découverte du Shadow SaaS</h2>
    <p>Découvrez les applications SaaS utilisées dans votre organisation qui n'ont peut-être pas été officiellement approuvées ou évaluées. Accédez à <span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Shadow SaaS</span>. Dans la v2.6.2, cette liste peut être alimentée automatiquement par l'intégration Shadow SaaS <a href="#shadow-saas-grip">Grip</a> ou <a href="#shadow-saas-hero">Hero</a>, et les applications non autorisées peuvent être bloquées dans <a href="#zscaler">Zscaler</a>.</p>
</div>


<!-- ================================================================
     VENDOR ONBOARDING & PROCUREMENT (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="onboarding-workflow">
    <h2>Intégration des fournisseurs &amp; Intégration par l'approvisionnement <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Une <strong>demande d'intégration de fournisseur</strong> est la manière dont un nouveau fournisseur entre dans la plateforme. Elle passe par une série de <strong>statuts</strong>, du premier brouillon à la décision finale. Avant que l'équipe cyber examine un fournisseur, celui-ci doit d'abord être <strong>intégré via votre processus d'approvisionnement</strong> et disposer d'un <strong>ID fournisseur (VID)</strong> valide. Cette section explique pourquoi et comment cela fonctionne exactement.</p>

    <h3>Le parcours d'intégration (statuts)</h3>
    <table class="doc-table">
        <tr><th>Statut</th><th>Ce que cela signifie</th></tr>
        <tr><td><span class="status-label">Brouillon</span></td><td>La demande est en cours de saisie. Elle n'a pas encore été envoyée pour révision.</td></tr>
        <tr><td><span class="status-label">Soumise</span></td><td>La demande a passé les vérifications de soumission et a été envoyée à l'équipe cyber.</td></tr>
        <tr><td><span class="status-label">En cours de révision</span></td><td>L'équipe cyber examine le fournisseur.</td></tr>
        <tr><td><span class="status-label">Examen IA</span></td><td>Les services du fournisseur utilisent l'IA et il est dans l'étape d'examen IA dédiée (voir <a href="#ai-review">Examen IA</a>).</td></tr>
        <tr><td><span class="status-label">Évaluation</span></td><td>Le fournisseur est en cours d'essai ou d'évaluation.</td></tr>
        <tr><td><span class="status-label">Approuvé</span></td><td>Le fournisseur a été approuvé et est intégré.</td></tr>
        <tr><td><span class="status-label">Rejeté</span></td><td>Le fournisseur n'a pas été approuvé.</td></tr>
        <tr><td><span class="status-label">Inactif</span></td><td>Le fournisseur n'est plus actif.</td></tr>
    </table>

    <h3>Trouver vos demandes de fournisseurs</h3>
    <p>Allez dans <span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Parties prenantes</span> &rarr; <span class="menu-label">Intégration des fournisseurs</span>. Vous verrez une liste de fournisseurs avec possibilité de recherche, affichant leur statut, niveau, score de sécurité (SRS) et des actions rapides (Voir, Modifier). Utilisez les filtres en haut (par exemple <strong>Tous</strong>, <strong>Approuvés</strong>, <strong>En révision</strong>) pour affiner la liste. Utilisez <span class="btn-label">+ Nouvelle demande</span> pour démarrer un nouveau fournisseur.</p>
    <figure class="doc-figure">
        <img src="app/docs/vendor-onboarding-list.png" alt="Vendor Onboarding Requests list" loading="lazy">
        <figcaption><strong>Liste d'intégration des fournisseurs.</strong> Recherche, filtres et actions par fournisseur. Le filtre <strong>En révision</strong> est une vue unique qui combine les fournisseurs <em>En cours de révision</em> et <em>Examen IA</em>.</figcaption>
    </figure>

    <h3 id="procurement-onboarding">Les deux éléments dont chaque fournisseur a besoin avant examen</h3>
    <p>Ouvrez un fournisseur et consultez la carte <strong>Informations fournisseur</strong>. Deux champs contrôlent si le fournisseur peut être soumis pour examen cyber :</p>
    <ul>
        <li><strong>Intégration par l'approvisionnement</strong> &mdash; un champ Oui/Non répondant à la question <em>« Ce fournisseur a-t-il complété l'intégration par l'approvisionnement ? »</em> Ce champ doit être défini sur <strong>Oui</strong>.</li>
        <li><strong>ID fournisseur (VID)</strong> &mdash; l'identifiant à 4&ndash;8 chiffres attribué au fournisseur par votre système d'approvisionnement. Ce doit être un numéro valide de 4&ndash;8 chiffres.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/vendor-info-card.png" alt="Vendor Information card showing Procurement Onboarding and Vendor ID fields" loading="lazy">
        <figcaption><strong>Carte Informations fournisseur.</strong> L'<strong>ID fournisseur (VID)</strong> et le champ d'intégration par l'approvisionnement doivent tous deux être remplis avant que le fournisseur puisse être soumis. <em>Remarque :</em> sur les instances mises à niveau depuis une version antérieure, ce champ peut encore s'intituler <strong>« VSU Onboarded »</strong> ; dans la v2.6.2, il est libellé <strong>« Procurement Onboarding »</strong> &mdash; c'est le même champ.</figcaption>
    </figure>

    <h3>Soumettre un fournisseur pour examen</h3>
    <ol class="steps">
        <li>Ouvrez le fournisseur depuis la liste <span class="menu-label">Intégration des fournisseurs</span> (la demande doit être en état <strong>Brouillon</strong>).</li>
        <li>Dans la carte <strong>Informations fournisseur</strong>, définissez <span class="field-label">Intégration par l'approvisionnement</span> sur <strong>Oui</strong> et entrez un <span class="field-label">ID fournisseur (VID)</span> valide (4&ndash;8 chiffres). Enregistrez vos modifications.</li>
        <li>Cliquez sur <span class="btn-label">Soumettre pour examen</span>. Il vous sera demandé de confirmer : <em>« Soumettre ce fournisseur pour examen ? Le fournisseur doit avoir un VID valide et être intégré à VSU. »</em></li>
        <li>Si les deux vérifications réussissent, le statut passe à <strong>Soumis</strong> et l'équipe cyber est notifiée.</li>
    </ol>
    <div class="callout callout-danger">
        <strong>Si la soumission est bloquée,</strong> vous verrez l'un de ces messages :
        <ul style="margin:8px 0 0;">
            <li>« Impossible de soumettre : Le fournisseur doit être intégré à VSU avant la soumission. Veuillez compléter l'évaluation d'intégration avec les détails VSU. » &rarr; définissez <strong>Intégration par l'approvisionnement</strong> sur <strong>Oui</strong>.</li>
            <li>« Impossible de soumettre : Un ID fournisseur (VID) valide est requis (4-8 chiffres). Veuillez compléter l'évaluation d'intégration avec l'ID fournisseur VSU. » &rarr; entrez un <strong>ID fournisseur</strong> valide de 4&ndash;8 chiffres.</li>
        </ul>
        Voir <a href="#troubleshooting">Dépannage</a> pour comprendre pourquoi cette règle existe.
    </div>
</div>

<div class="doc-section" id="custom-onboarding">
    <h2>Champs d'intégration personnalisés &amp; l'onglet Données personnalisées <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Les champs fournisseur standard (nom, domaine, niveau, TVA, etc.) couvrent la plupart des besoins, mais chaque organisation suit quelque chose de supplémentaire. Dans la v2.6.2, un modèle d'intégration peut définir des <strong>champs personnalisés</strong> qui n'ont aucune colonne fournisseur standard. Leurs valeurs sont capturées par fournisseur et affichées dans l'onglet <strong>Données personnalisées</strong> du fournisseur.</p>

    <h3>Où résident les valeurs personnalisées : l'onglet Données personnalisées</h3>
    <p>Ouvrez un fournisseur (<span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Intégration des fournisseurs</span> &rarr; <span class="menu-label">Mes fournisseurs</span> &rarr; ouvrez un fournisseur). Si le modèle d'intégration du fournisseur définit des champs personnalisés, un onglet <strong>Données personnalisées</strong> apparaît à côté des autres onglets du fournisseur, avec un décompte du nombre de valeurs personnalisées au dossier. L'onglet est en lecture seule jusqu'à ce que vous cliquiez sur <span class="btn-label">Modifier</span> ; effectuez vos modifications et cliquez sur <span class="btn-label">Enregistrer les données personnalisées</span>. Les champs sont regroupés par section de modèle. Les utilisateurs autorisés à voir un champ mais pas à le modifier le voient marqué <em>(lecture seule)</em>.</p>

    <h3>Définir un champ personnalisé (administrateurs)</h3>
    <p>Un champ personnalisé est simplement une question sur un modèle de catégorie <strong>Intégration</strong> dont le <span class="field-label">Nom du champ</span> n'est <em>pas</em> une colonne fournisseur intégrée. Il y a deux étapes, toutes deux dans le Portail d'administration :</p>
    <ol class="steps">
        <li><strong>Enregistrez le nom du champ.</strong> Allez dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Référence des champs</span>, cliquez sur <span class="btn-label">+ Ajouter un champ</span>, et ajoutez votre champ personnalisé (lettres minuscules, chiffres et traits de soulignement ; par ex. <code>data_residency_region</code>). Choisissez un type de colonne (texte, nombre, date, etc.) et la catégorie <span class="field-label">Intégration</span>.</li>
        <li><strong>Ajoutez une question qui y est mappée.</strong> Dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Générateur de modèles</span>, ouvrez votre modèle d'intégration, ajoutez une question et définissez son <span class="field-label">Nom du champ</span> sur le champ que vous venez d'enregistrer. Voir <a href="#admin-templates">Générateur de modèles d'évaluation</a>.</li>
    </ol>

    <h3>Nouveaux types de champs pour des réponses plus riches <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Au-delà des types texte, nombre, date, liste déroulante et bouton radio existants, les questions (personnalisées ou standard) peuvent désormais utiliser :</p>
    <table class="doc-table">
        <tr><th>Type</th><th>Ce que voit le fournisseur</th></tr>
        <tr><td><strong>Cases à cocher</strong></td><td>Une liste à choix multiple &mdash; cochez chaque option applicable.</td></tr>
        <tr><td><strong>Groupe de boutons (multiple)</strong></td><td>Le même choix multiple, affiché sous forme de rangée de boutons bascule.</td></tr>
        <tr><td><strong>Téléphone</strong></td><td>Un numéro de téléphone avec un sélecteur d'indicatif pays &amp; drapeau (voir <a href="#question-types">Types de questions Téléphone &amp; TVA</a>).</td></tr>
        <tr><td><strong>Numéro de TVA</strong></td><td>Un numéro de TVA UE avec double saisie et validation VIES en direct (voir <a href="#question-types">Types de questions Téléphone &amp; TVA</a>).</td></tr>
    </table>
    <p>Les équivalents à choix unique (<strong>Liste déroulante</strong>, <strong>Boutons radio</strong>, <strong>Groupe de boutons</strong>) sont toujours disponibles. Les types à choix multiple nécessitent une liste d'<span class="field-label">Options</span> (une par ligne).</p>

    <h3>Contrôler qui peut voir et modifier un champ (restriction par rôle) <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Sur les modèles d'intégration, chaque section et question <strong>personnalisée</strong> comporte deux contrôles de rôle, afin que vous puissiez tenir les champs sensibles à l'écart des personnes qui ne devraient pas les voir :</p>
    <ul>
        <li><span class="field-label">Visible par les rôles</span> &mdash; quels rôles peuvent <em>voir</em> le champ.</li>
        <li><span class="field-label">Rôles avec accès en visualisation et modification</span> &mdash; quels rôles peuvent le <em>modifier</em>.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Les champs personnalisés sont privés par défaut.</strong> Contrairement à une question standard, un champ personnalisé est masqué jusqu'à ce que vous accordiez un rôle. Tant qu'aucun rôle n'est accordé, seuls les super administrateurs peuvent le voir ou le modifier. Un champ qu'un utilisateur n'est pas autorisé à voir est exclu de l'onglet Données personnalisées, de la page fournisseur, de l'export CSV et de l'API pour cette personne. (Cette valeur par défaut d'octroi uniquement s'applique aux champs personnalisés ; les questions d'intégration standard ne sont jamais restreintes de cette façon.)
    </div>
    <p>L'octroi d'une section et l'octroi d'une question doivent tous deux autoriser une personne avant qu'elle ne voie cette question, vous pouvez donc masquer une section entière ou seulement des champs individuels à l'intérieur.</p>

    <h3>Valeurs personnalisées dans les exports et l'API</h3>
    <ul>
        <li><strong>Export CSV.</strong> Sur la liste <span class="menu-label">Intégration des fournisseurs</span>, <span class="btn-label">Exporter en CSV</span> (administrateurs et Cyber TPRM) ajoute désormais une colonne par champ personnalisé, nommée <code>custom:&lt;field_name&gt;</code>, à côté des colonnes standard.</li>
        <li><strong>API REST.</strong> La réponse pour un seul fournisseur (<code>GET /vendors/{id}</code>) inclut un tableau <code>custom_onboarding_data</code> ; chaque entrée contient <code>field_name</code>, <code>label</code>, <code>value</code>, <code>type</code>, <code>section</code> et <code>template_name</code>.</li>
    </ul>
    <p>Les deux lisent depuis le même endroit que l'onglet Données personnalisées et respectent la même visibilité par rôle &mdash; un champ que l'appelant (ou le propriétaire de la clé API) ne peut pas voir est laissé vide ou omis.</p>
</div>

<div class="doc-section" id="ai-review">
    <h2>Examen IA pour les fournisseurs <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Certains fournisseurs proposent des services qui utilisent l'intelligence artificielle. Ces fournisseurs peuvent présenter des risques différents, c'est pourquoi la v2.6.2 ajoute un statut dédié d'<strong>Examen IA</strong> pour les suivre séparément durant le processus d'examen.</p>

    <h3>Comment un fournisseur entre en Examen IA</h3>
    <p>Sur la carte <strong>Informations fournisseur</strong>, il y a un champ <span class="field-label">Les services utilisent l'IA</span>. Lorsque ce champ est défini sur <strong>Oui</strong>, un examinateur autorisé (un utilisateur <strong>Cyber TPRM</strong> ou un <strong>Administrateur</strong>, en mode édition du fournisseur) voit un lien <span class="btn-label">Forcer l'examen IA</span> directement sous ce champ.</p>
    <ol class="steps">
        <li>Ouvrez le fournisseur et confirmez que <span class="field-label">Les services utilisent l'IA</span> est défini sur <strong>Oui</strong>.</li>
        <li>Cliquez sur <span class="btn-label">Forcer l'examen IA</span>. Confirmez l'invite : <em>« Forcer ce fournisseur en Examen IA ? »</em></li>
        <li>Le statut du fournisseur passe à <strong>Examen IA</strong>.</li>
    </ol>
    <div class="callout callout-info">
        <strong>Pourquoi le lien pourrait-il ne pas apparaître ?</strong> Le lien <strong>Forcer l'examen IA</strong> s'affiche uniquement lorsque (1) vous avez la permission d'approuver, (2) vous êtes en mode édition, (3) <strong>Les services utilisent l'IA</strong> est sur <strong>Oui</strong>, et (4) le fournisseur n'est pas déjà en Examen IA. Si <strong>Les services utilisent l'IA</strong> est sur « Non », vous verrez le message <em>« L'examen IA ne peut être forcé que pour les fournisseurs dont les services utilisent l'IA. »</em></p>
    </div>
    <p>Sur la page <a href="#procurement-cyber-status">Statut cyber de l'approvisionnement</a> et sur le filtre <strong>En révision</strong> de la liste des fournisseurs, les fournisseurs en <strong>Cours de révision</strong> et en <strong>Examen IA</strong> sont affichés ensemble &mdash; ainsi rien de ce qui est en cours d'examen n'est jamais masqué simplement parce qu'il est examiné avec l'IA.</p>
</div>

<div class="doc-section" id="procurement-cyber-status">
    <h2>Statut cyber de l'approvisionnement <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>La page <strong>Statut cyber</strong> offre à l'<strong>équipe d'approvisionnement</strong> une vue simple et toujours à jour des fournisseurs que l'équipe cyber examine et des dernières informations sur chacun d'eux &mdash; sans avoir besoin d'accéder aux outils de sécurité complets. L'équipe cyber publie de courtes mises à jour datées ; l'approvisionnement les lit ici (et dans un e-mail hebdomadaire).</p>
    <p>Ouvrez-la depuis <span class="menu-label">Module TPRM</span> &rarr; <span class="menu-label">Approvisionnement</span> &rarr; <span class="menu-label">Statut cyber</span>. Elle est disponible pour les utilisateurs <strong>Approvisionnement</strong>, <strong>Cyber TPRM</strong> et <strong>Administrateur</strong>.</p>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status.png" alt="Procurement Cyber Status page" loading="lazy">
        <figcaption><strong>Approvisionnement &rarr; Statut cyber.</strong> Liste tous les fournisseurs dont le statut est <em>En cours de révision</em> ou <em>Examen IA</em>, avec le nombre de mises à jour et la date de la dernière mise à jour. Lorsqu'aucun fournisseur n'est en cours de révision, le tableau est remplacé par le message « Aucun fournisseur en cours de révision ».</figcaption>
    </figure>

    <h3>Consulter l'historique des mises à jour d'un fournisseur</h3>
    <ol class="steps">
        <li>Cliquez sur le nom d'un fournisseur dans le tableau <strong>Fournisseurs en cours de révision</strong>.</li>
        <li>Le panneau <strong>Historique des mises à jour d'approvisionnement</strong> s'ouvre, affichant toutes les mises à jour de la plus récente à la plus ancienne : la date et l'heure, qui l'a rédigée, le statut du fournisseur à ce moment-là et la note elle-même.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status-updates.png" alt="Procurement Update History for a vendor in review" loading="lazy">
        <figcaption><strong>Historique des mises à jour d'un fournisseur.</strong> Cliquer sur le nom d'un fournisseur ouvre son <strong>Historique des mises à jour d'approvisionnement</strong>. Chaque entrée affiche la date et l'heure, l'auteur, un badge indiquant le statut du fournisseur au moment de la rédaction de la note, et la note de l'équipe cyber &mdash; afin que l'approvisionnement puisse voir exactement où en est chaque examen. Les historiques longs sont paginés avec le contrôle <em>Afficher par page</em>.</figcaption>
    </figure>

    <h3>Pour les examinateurs cyber : publier une mise à jour pour l'approvisionnement</h3>
    <p>Les utilisateurs Cyber TPRM et les administrateurs peuvent publier une mise à jour pour un ou plusieurs fournisseurs à la fois :</p>
    <ol class="steps">
        <li>Sur la page <strong>Statut cyber</strong>, cochez la case à côté de chaque fournisseur que vous souhaitez mettre à jour.</li>
        <li>Cliquez sur <span class="btn-label">Fournir une mise à jour à l'approvisionnement</span>.</li>
        <li>Dans la fenêtre <strong>Fournir une mise à jour à l'approvisionnement</strong>, tapez votre note dans la zone <span class="field-label">Mise à jour</span>.</li>
        <li>Utilisez facultativement <span class="field-label">Changer le statut</span> pour faire avancer le(s) fournisseur(s) (par exemple vers <strong>Évaluation</strong>, <strong>Approuvé</strong> ou <strong>Rejeté</strong>). Laissez-le sur <em>Conserver le statut actuel</em> pour ajouter uniquement une note.</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer la mise à jour</span>. La mise à jour est enregistrée pour chaque fournisseur sélectionné.</li>
    </ol>

    <h3>L'e-mail hebdomadaire de résumé d'approvisionnement</h3>
    <p>Pour tenir l'approvisionnement informé sans que quiconque ait besoin de se connecter, la plateforme peut envoyer par e-mail un <strong>résumé hebdomadaire</strong> listant tous les fournisseurs en cours de révision avec leur mise à jour la plus récente. Par défaut, il est envoyé <strong>chaque lundi à 7h00</strong>.</p>
    <ol class="steps">
        <li>Un administrateur va dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Paramètres de messagerie</span> et trouve les options <strong>Résumé des mises à jour d'approvisionnement</strong>.</li>
        <li>Activez le résumé et entrez une ou plusieurs adresses e-mail de destinataires (séparées par des virgules).</li>
        <li>Enregistrez. Vous pouvez également en envoyer un immédiatement avec <span class="btn-label">Envoyer le résumé maintenant</span> pour le tester.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-scheduler.png" alt="Admin Scheduler showing the Procurement Update Digest job" loading="lazy">
        <figcaption><strong>Admin &rarr; Planificateur.</strong> La tâche <strong>Résumé des mises à jour d'approvisionnement</strong> (en bas de la liste) s'exécute chaque semaine. Le Planificateur est l'endroit où les administrateurs activent, désactivent et planifient toutes les tâches automatisées.</figcaption>
    </figure>
</div>

<!-- ================================================================
     INTEGRATIONS: GRIP + ZSCALER (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="shadow-saas-grip">
    <h2>Intégration Grip Shadow SaaS <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Le « Shadow SaaS » désigne les applications cloud utilisées par les employés qui n'ont jamais été officiellement approuvées. <strong>Grip Security</strong> est un service qui découvre ces applications. Dans la v2.6.2, vous pouvez connecter votre compte Grip afin que la plateforme importe automatiquement les applications que Grip découvre &mdash; ainsi que le nombre de personnes qui utilisent chacune, un score de risque et des alertes de sécurité &mdash; et les liste sur votre page <a href="#tprm-shadow-saas">Shadow SaaS</a>.</p>
    <p>Il est configuré par un administrateur dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> sous l'onglet <strong>Grip</strong>. Grip est l'un des deux fournisseurs Shadow SaaS (l'autre est <a href="#shadow-saas-hero">Hero</a>) ; un seul peut être activé à la fois.</p>

    <h3>Connexion à Grip (étape par étape)</h3>
    <ol class="steps">
        <li>Dans Grip, créez un <strong>jeton API</strong> et notez l'URL de base de votre tenant (elle se termine par <code>/public/saas</code>, par exemple <code>https://tenant.dep.grip.security/public/saas</code>).</li>
        <li>Dans la plateforme, allez dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> et trouvez la carte <strong>Connexion Grip Security</strong>.</li>
        <li>Cochez <span class="field-label">Activer l'intégration Grip Security</span>.</li>
        <li>Collez l'URL de votre tenant dans <span class="field-label">Serveur (URL de base du tenant)</span> et votre jeton dans <span class="field-label">Jeton API</span>.</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer la configuration</span>, puis sur <span class="btn-label">Tester la connexion</span> pour confirmer. Un message de succès ressemble à <em>« Connecté à Grip — l'exemple a retourné 1 enregistrement(s) »</em>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grip-card.png" alt="Grip Security Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Connexion Grip Security.</strong> Entrez l'URL de votre tenant et le jeton API, enregistrez, puis testez.</figcaption>
    </figure>

    <h3>Maintenir les données à jour automatiquement</h3>
    <p>Utilisez la carte partagée <strong>Réhydratation planifiée</strong> (sous les onglets de fournisseurs) pour actualiser les données du fournisseur activé selon un calendrier. Cochez <span class="field-label">Activer la réhydratation planifiée</span> et entrez une <span class="field-label">Planification (expression cron)</span> &mdash; par exemple <code>0 2 * * *</code> pour une actualisation quotidienne à 2h00 ; la carte affiche un résumé en langage courant de ce que vous avez saisi. La tâche est installée automatiquement dans le planificateur système (aucune étape manuelle sur le serveur) et survit aux redémarrages. Vous pouvez également cliquer sur <span class="btn-label">Exécuter maintenant</span> pour actualiser immédiatement. Le même calendrier s'applique au fournisseur actuellement activé (Grip ou Hero).</p>

    <h3>Ce que vous verrez ensuite</h3>
    <p>Les applications découvertes apparaissent sur la page <span class="menu-label">Shadow SaaS</span> en tant qu'entrées <strong>En attente</strong> avec un score de risque (affiché sur une échelle de 1&ndash;5), une catégorie et un nombre d'utilisateurs. À partir de là, vous pouvez <strong>Autoriser</strong> une application (ce qui commence à l'intégrer en tant que fournisseur), la <strong>Refuser</strong> (la marquer comme non autorisée et éventuellement la bloquer dans Zscaler), ou la <strong>Ignorer</strong>.</p>
    <figure class="doc-figure">
        <img src="app/docs/shadow-saas.png" alt="Shadow SaaS list page" loading="lazy">
        <figcaption><strong>La page Shadow SaaS.</strong> Applications découvertes et importées avec leurs risques et actions. Les applications intégrées en tant que fournisseurs sont ignorées lors des synchronisations futures, et tout ce que vous ignorez reste ignoré.</figcaption>
    </figure>

    <h3>Source de données Live vs. Local (en cache) <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Sur la carte Connexion Grip Security, <span class="field-label">Source de données</span> contrôle d'où les pages Grip lisent :</p>
    <ul>
        <li><strong>Live</strong> &mdash; appelle l'API Grip pour chaque page. Toujours à jour, mais plus lourd pour l'API.</li>
        <li><strong>Local (hydraté/en cache)</strong> &mdash; sert à partir de la copie des données Grip conservée dans la base de données de la plateforme. Plus léger pour l'API. En mode Local, chaque synchronisation <strong>actualise entièrement</strong> cette copie ; entre les synchronisations, les pages servent à partir de l'instantané plutôt que d'appeler Grip.</li>
    </ul>

    <h3>Surveiller et contrôler une synchronisation <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Pendant qu'une synchronisation est en cours, la carte <strong>Dernière synchronisation</strong> affiche un relevé de progression en direct &mdash; <em>&ldquo;Hydratation des listes d'utilisateurs par application &mdash; NN% (D / T applications)&rdquo;</em> &mdash; au-dessus d'un bouton <span class="btn-label">Arrêter la synchronisation</span> qui annule l'exécution de manière coopérative. Pour effacer entièrement les données Grip servies localement, utilisez <span class="btn-label">Vider les données</span> sur la même carte : cela efface les tables miroir Grip, les lignes Grip de la liste Shadow SaaS et la télémétrie Grip apposée sur les fiches fournisseurs (l'onglet Données SaaS). Votre historique de synchronisation est conservé, et la synchronisation suivante réhydrate tout à partir de Grip.</p>

    <h3>Note SecurityScorecard (SSC) <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Lorsque Grip est connecté, une colonne <strong>SSC</strong> affiche la note en lettre <strong>SecurityScorecard</strong> (A&ndash;F) de chaque application ou fournisseur sur la liste Shadow SaaS, sur la liste SRS des fournisseurs et sur l'onglet Données SaaS du fournisseur. Elle n'apparaît que lorsque Grip est activé.</p>

    <h3>L'onglet &ldquo;Données SaaS&rdquo; du fournisseur <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Lorsqu'un fournisseur correspond à une application découverte par Grip, un onglet <strong>Données SaaS</strong> en lecture seule apparaît sur la page de ce fournisseur, faisant remonter la télémétrie Grip recueillie lors de la synchronisation sans quitter le fournisseur : <strong>Première découverte</strong>, <strong>Comptes actifs</strong> (un lien vers la liste des utilisateurs affectés), <strong>Dernière utilisation connue</strong>, classification de l'application, note <strong>Security Scorecard</strong>, catégorie, profondeur d'IA, signaux de conformité et prise en charge SAML/MFA.</p>

    <h3>Alertes de violation Grip <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>Grip peut également alimenter la plateforme en incidents de sécurité. Cochez <span class="field-label">Faire remonter les informations de violation Grip dans les Alertes de violation / cyber</span> sur la carte de connexion et les alertes Grip &ldquo;Security Incident Detected&rdquo; sont écrites dans votre liste <a href="#breach-alerts">Alertes de violation / cyber</a> à chaque synchronisation. (Cela nécessite également que la fonctionnalité Alertes de violation / cyber soit activée sous <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Paramètres de messagerie</span>.)</p>
    <div class="callout callout-info">
        <strong>Les données personnelles sont chiffrées au repos.</strong> Les noms, adresses e-mail et autres détails personnels dans les données Grip mises en cache sont chiffrés dans la base de données et déchiffrés uniquement lorsqu'ils sont affichés dans l'application ou renvoyés par l'API. Ceci est automatique et ne nécessite aucune configuration.
    </div>
</div>

<div class="doc-section" id="shadow-saas-hero">
    <h2>Intégration Hero Shadow SaaS <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p><strong>HERO Security</strong> est un fournisseur alternatif de Shadow SaaS. Au lieu de Grip, vous pouvez connecter un compte HERO et la plateforme importe les fournisseurs que HERO découvre &mdash; avec leur statut, un score de risque, le contact le plus actif et un nombre d'utilisateurs &mdash; dans la même liste <a href="#tprm-shadow-saas">Shadow SaaS</a>. Grip et Hero sont <strong>mutuellement exclusifs</strong> : activer Hero désactive automatiquement Grip (et vice-versa), de sorte que la liste est toujours alimentée par exactement un fournisseur.</p>
    <p>Il est configuré par un administrateur dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> sous l'onglet <strong>Hero</strong>.</p>

    <h3>Connexion à Hero (étape par étape)</h3>
    <ol class="steps">
        <li>Dans le panneau d'administration HERO, créez un <strong>client API</strong> et copiez son <strong>Client ID</strong> et son <strong>Client Secret</strong> (le secret n'est affiché qu'une seule fois).</li>
        <li>Dans la plateforme, allez dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> et ouvrez l'onglet <strong>Hero</strong> pour trouver la carte <strong>Connexion HERO Security</strong>.</li>
        <li>Cochez <span class="field-label">Activer l'intégration HERO Security</span> (cela désactive Grip).</li>
        <li>Laissez <span class="field-label">Serveur (URL de base)</span> sur <code>https://api.herosecurity.ai/stable</code> sauf indication contraire, et collez votre <span class="field-label">Client ID</span> et votre <span class="field-label">Client Secret</span>.</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer la configuration</span>, puis sur <span class="btn-label">Tester la connexion</span>. Un message de succès ressemble à <em>« Connecté à HERO — l'exemple a retourné 1 enregistrement(s) »</em>.</li>
    </ol>

    <h3>Ce que vous verrez ensuite</h3>
    <p>Les fournisseurs HERO apparaissent sur la page <span class="menu-label">Shadow SaaS</span> de la même façon que les applications Grip &mdash; en tant qu'entrées <strong>En attente</strong> que vous pouvez Autoriser, Refuser ou Ignorer. Pour chaque fournisseur, la plateforme enregistre :</p>
    <ul>
        <li><strong>Score de risque (1&ndash;5)</strong> &mdash; dérivé du problème de sécurité ouvert le plus grave que HERO a pour ce fournisseur (critique&nbsp;=&nbsp;5 jusqu'à faible&nbsp;=&nbsp;2 ; les fournisseurs sans problèmes ouverts ne sont pas notés). C'est la même échelle de 1&ndash;5 que Grip utilise.</li>
        <li><strong>Responsable de la relation</strong> &mdash; le contact observé le plus actif du fournisseur (l'utilisateur ayant la plus haute activité par e-mail).</li>
        <li><strong>Nombre d'utilisateurs</strong> &mdash; combien d'utilisateurs ont été observés interagissant avec le fournisseur.</li>
        <li><strong>Type de risque</strong> &mdash; un résumé des signaux d'engagement de HERO (autorisation, activité, engagement commercial) et le nombre de problèmes ouverts.</li>
    </ul>
    <p>Certaines colonnes fournies par d'autres sources (catégorie d'application, support MFA, historique des violations, volumes de trafic, partage de fichiers) ne font pas partie de l'API HERO, elles restent donc vides pour les lignes Hero.</p>

    <div class="callout callout-info">
        <strong>Avertissement sur le temps de synchronisation.</strong> HERO retourne ses données par fournisseur et limite le débit des requêtes, donc une actualisation complète d'un grand tenant s'exécute pendant plusieurs minutes en arrière-plan. La tâche planifiée et « Exécuter maintenant » s'auto-régulent automatiquement pour rester dans les limites de HERO.
    </div>
</div>

<div class="doc-section" id="zscaler">
    <h2>Intégration de blocage Zscaler <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p><strong>Zscaler</strong> est un service de sécurité web capable de bloquer l'accès à des sites web. Grâce à cette intégration, lorsque vous <strong>Refusez</strong> une application non autorisée sur la page Shadow SaaS, la plateforme peut automatiquement ajouter le domaine web de cette application à une liste de blocage dans votre compte Zscaler &mdash; afin que les utilisateurs ne puissent plus y accéder. Cliquer sur <strong>Autoriser</strong> par la suite supprime le blocage.</p>
    <p>Il est configuré par un administrateur dans <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span>, sur la carte <strong>Connexion Zscaler</strong>.</p>

    <h3>Connexion à Zscaler (étape par étape)</h3>
    <ol class="steps">
        <li>Dans Zscaler (ZIdentity), créez un <strong>client API</strong> et copiez son <strong>Client ID</strong> et son <strong>Client Secret</strong>. Notez votre <strong>domaine personnalisé</strong> (la partie avant <code>.zslogin.net</code>).</li>
        <li>Dans ZIA, créez (ou sélectionnez) une <strong>catégorie d'URL personnalisée</strong> à laquelle les domaines bloqués seront ajoutés, et notez son nom exact.</li>
        <li>Dans la carte <strong>Connexion Zscaler</strong> de la plateforme, cochez <span class="field-label">Activer le blocage par catégorie d'URL Zscaler lors du refus</span>.</li>
        <li>Remplissez <span class="field-label">URL de l'API</span> (par défaut <code>https://api.zsapi.net</code>), <span class="field-label">Domaine personnalisé ZIdentity</span>, <span class="field-label">Client ID</span>, <span class="field-label">Client Secret</span> et le nom de la <span class="field-label">Catégorie d'URL</span>.</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer la configuration</span>, puis sur <span class="btn-label">Tester la connexion</span> pour confirmer que les identifiants fonctionnent.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/zscaler-card.png" alt="Zscaler Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Connexion Zscaler.</strong> Lorsqu'activé, le bouton <strong>Refuser</strong> sur une application Shadow SaaS ajoute son domaine à la catégorie d'URL que vous nommez ici. La catégorie doit déjà exister dans Zscaler.</figcaption>
    </figure>
    <div class="callout callout-info">
        <strong>Si le blocage est désactivé,</strong> refuser une application la marque uniquement comme non autorisée dans la plateforme ; rien n'est envoyé à Zscaler. Vous verrez <em>« Marquée non autorisée. L'intégration Zscaler n'est pas activée ; le domaine n'a pas été ajouté à la catégorie d'URL. »</em>
    </div>
</div>


<div class="doc-section" id="breach-alerts">
    <h2>Alertes de violation / cyber <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>La page <strong>Alertes de violation / cyber</strong> regroupe en un seul endroit les signaux de violation et de renseignement sur les menaces pour votre chaîne d'approvisionnement de fournisseurs. Ouvrez-la depuis la barre latérale sous <span class="menu-label">Alertes de violation / cyber</span> &rarr; <span class="menu-label">Alertes de violation</span> ; un badge rouge indique le nombre de nouvelles alertes.</p>

    <h3>D'où proviennent les alertes</h3>
    <p>Les alertes comprennent les violations révélées par les analyseurs IA de violations &amp; OSINT (voir <a href="#admin-ai">Intégration IA</a>) et, lorsqu'ils sont activés, les incidents de sécurité de <a href="#shadow-saas-grip">Grip</a>. Chaque alerte affiche l'entité affectée, les utilisateurs potentiellement impactés, la technologie et la date de détection. Un incident sur une application SaaS que vous n'avez <strong>pas</strong> intégrée en tant que fournisseur est étiqueté <strong>&ldquo;Shadow SaaS&rdquo;</strong> avec le nombre d'utilisateurs potentiellement impactés ; si cette application est intégrée ultérieurement, les incidents futurs sont plutôt rattachés au fournisseur.</p>

    <h3>Qui a été affecté</h3>
    <p>Pour un incident provenant de Grip, le nombre d'utilisateurs impactés renvoie à une liste des <strong>utilisateurs affectés</strong> pour cette application. La liste est paginée et filtrable (par exemple par méthode d'authentification) et dispose d'une zone <strong>Rechercher par nom ou e-mail</strong> pour trouver une personne spécifique. Comme la liste des utilisateurs est stockée chiffrée, la recherche s'exécute sur les données déchiffrées dans l'application, elle fonctionne donc de la même manière que le tri et la pagination.</p>

    <h3>Traiter les alertes en masse</h3>
    <p>Les administrateurs et les utilisateurs Cyber TPRM disposent d'une barre d'outils à sélection multiple sur la liste. Cochez les alertes souhaitées (ou utilisez <strong>Tout cocher</strong>) et appliquez une action à toutes en même temps :</p>
    <ul>
        <li><span class="btn-label">Accuser réception</span> &mdash; marquez les alertes comme vues.</li>
        <li><span class="btn-label">Faux positif</span> &mdash; marquez-les comme n'étant pas un problème réel.</li>
        <li><span class="btn-label">Supprimer</span> &mdash; supprimez-les. <strong>Administrateurs uniquement</strong>, et confirmé avant exécution.</li>
    </ul>
</div>


<!-- ================================================================
     ADMIN PORTAL
     ================================================================ -->
<div class="doc-section" id="admin-general">
    <h2>Portail d'administration : Paramètres généraux</h2>
    <p>Le Portail d'administration est accessible via <span class="menu-label">Administration</span> dans la barre latérale (utilisateurs administrateurs uniquement) ou le lien <span class="btn-label">Admin</span> dans la barre supérieure.</p>
    <p>Les paramètres généraux comprennent : le nom de l'application, le nom de l'entreprise, l'e-mail d'assistance et les options de configuration à l'échelle du système.</p>
</div>

<div class="doc-section" id="admin-branding">
    <h2>Image de marque &amp; Thème</h2>
    <p>Personnalisez l'apparence de la plateforme : importez le logo de votre entreprise, définissez les couleurs de la barre latérale, de l'en-tête, des boutons et la largeur de la navigation. Accédez à <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Image de marque</span>.</p>
</div>

<div class="doc-section" id="admin-users">
    <h2>Gestion des utilisateurs</h2>
    <p>Gérez les comptes utilisateurs et les affectations de groupes. Accédez à <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Utilisateurs</span>.</p>

    <h3>Affecter des utilisateurs aux groupes ACL</h3>
    <ol class="steps">
        <li>Accédez à <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Utilisateurs</span>.</li>
        <li>Trouvez l'utilisateur dans la liste.</li>
        <li>Cliquez sur le bouton <span class="btn-label">Groupes</span> à côté du nom de l'utilisateur.</li>
        <li>Une fenêtre modale apparaîtra affichant tous les groupes disponibles avec des cases à cocher. Cochez les groupes que vous souhaitez affecter (par ex., <strong>Cyber GRC</strong>, <strong>Administrateur</strong>).</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer les modifications</span>.</li>
    </ol>
    <p>Au-delà de l'affectation des groupes fournis, les super administrateurs peuvent créer leurs propres groupes avec un ensemble de permissions personnalisé &mdash; voir <a href="#admin-acl-groups">Groupes ACL &amp; Contrôle d'accès personnalisé</a>.</p>
</div>

<div class="doc-section" id="admin-acl-groups">
    <h2>Groupes ACL &amp; Contrôle d'accès personnalisé <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>La plateforme est livrée avec sept groupes intégrés (administrator, cyber_tprm, procurement, stakeholder, auditor, cyber_grc, grc_contributors). Dans la v2.6.2, les <strong>super administrateurs</strong> peuvent également créer leurs propres groupes et ajuster exactement ce que chacun peut faire. Ouvrez <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Contrôle d'accès</span> &rarr; <span class="menu-label">Groupes ACL</span>. Tout administrateur peut consulter cette page ; seuls les super administrateurs voient les contrôles de création, de modification et de permission.</p>

    <h3>Les groupes fournis sont protégés</h3>
    <p>Les sept groupes intégrés sont marqués <strong>Système</strong>. Ils ne peuvent être ni supprimés ni renommés, et leurs permissions sont en lecture seule &mdash; vous pouvez ouvrir <span class="btn-label">Voir les permissions</span> pour voir exactement ce qu'ils accordent, mais pas les modifier. Cela maintient stables les valeurs par défaut sur lesquelles tout le monde s'appuie.</p>

    <h3>Créer un groupe personnalisé</h3>
    <ol class="steps">
        <li>Cliquez sur <span class="btn-label">+ Créer un groupe</span>.</li>
        <li>Entrez un <span class="field-label">Nom de groupe (machine)</span> (lettres minuscules, chiffres, traits de soulignement &mdash; il est fixé une fois créé), un <span class="field-label">Nom d'affichage</span> convivial et une <span class="field-label">Description</span>.</li>
        <li>Utilisez facultativement <span class="field-label">Copier les permissions de</span> pour <strong>cloner</strong> un groupe existant (y compris un groupe Système) comme point de départ &mdash; puis affinez-le. Laissez sur <em>&mdash; Commencer sans aucune permission &mdash;</em> pour partir de rien.</li>
        <li>Cliquez sur <span class="btn-label">Créer le groupe</span>.</li>
    </ol>

    <h3>Ajuster la matrice des permissions</h3>
    <p>Ouvrez les <span class="btn-label">Permissions</span> d'un groupe personnalisé. Les permissions sont regroupées par module (Intégration des fournisseurs, Analyse FAIR, Évaluations, Notation de sécurité (SRS), Révisions annuelles, GRC et Autres). Chaque permission est étiquetée soit <strong>Lecture</strong>, soit <strong>Lecture/Écriture</strong>, et chaque module dispose de trois préréglages en un clic :</p>
    <ul>
        <li><span class="btn-label">Lecture</span> &mdash; accorde uniquement les permissions de consultation/liste/export pour ce module.</li>
        <li><span class="btn-label">Lecture &amp; Écriture</span> &mdash; accorde tout (consulter <em>et</em> modifier).</li>
        <li><span class="btn-label">Aucune</span> &mdash; efface le module.</li>
    </ul>
    <p>Cliquez sur <span class="btn-label">Enregistrer les permissions</span> une fois terminé. Accorder la <strong>Lecture</strong> n'implique jamais l'accès en écriture &mdash; la capacité de modifier quelque chose est toujours un octroi séparé et explicite. Toutes les modifications de groupe sont consignées dans le journal d'audit.</p>
</div>

<div class="doc-section" id="admin-templates">
    <h2>Générateur de modèles d'évaluation <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Le <strong>Générateur de modèles</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Générateur de modèles</span>) est l'endroit où les administrateurs et les utilisateurs Cyber TPRM conçoivent les questionnaires d'évaluation et d'intégration. Utilisez <span class="btn-label">+ Ajouter une section</span> et <span class="btn-label">+ Ajouter une question</span> pour construire un modèle. Quelques ajouts de la v2.6.2 méritent d'être signalés.</p>

    <h3>Types de questions et mapping des champs</h3>
    <p>Le <span class="field-label">Type de question</span> d'une question inclut désormais <strong>Téléphone</strong>, <strong>Numéro de TVA</strong>, <strong>Cases à cocher</strong> et <strong>Groupe de boutons (multiple)</strong> en plus des types texte, liste déroulante et radio habituels (voir <a href="#custom-onboarding">Champs d'intégration personnalisés</a> pour savoir ce que chacun capture). Le <span class="field-label">Nom du champ</span> d'une question mappe sa réponse sur un champ fournisseur ; choisissez un champ intégré ou un champ personnalisé que vous avez enregistré sous <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Référence des champs</span>.</p>

    <h3>Instructions d'import de certificat</h3>
    <p>Sur un modèle, vous pouvez renseigner les <span class="field-label">Instructions d'import de certificat</span> &mdash; le texte affiché à un fournisseur dans l'invite <em>&ldquo;Avez-vous un certificat ?&rdquo;</em>. Cela permet à un modèle d'inviter n'importe quel certificat (SOC 2 Type 2, ISO 27001, etc.), pas seulement ISO 27001. Si vous le laissez vide, un message générique est affiché.</p>

    <h3>Visibilité par rôle sur les modèles d'intégration</h3>
    <p>Pour les modèles d'<strong>intégration</strong>, les sections et questions personnalisées comportent des contrôles <span class="field-label">Visible par les rôles</span> et <span class="field-label">Rôles avec accès en visualisation et modification</span>, afin que vous décidiez qui peut voir et modifier chaque champ personnalisé. Voir <a href="#custom-onboarding">Champs d'intégration personnalisés &amp; l'onglet Données personnalisées</a>.</p>

    <h3>Les modèles désactivés sont masqués par défaut</h3>
    <p>La liste des modèles n'affiche que les modèles <strong>actifs</strong>. Si certains ont été désactivés, un bouton <span class="btn-label">Afficher les désactivés (N)</span> les révèle (et bascule sur <span class="btn-label">Masquer les désactivés (N)</span>), gardant la liste d'un tenant de longue date centrée sur les modèles réellement utilisés sans perdre l'accès à ceux qui ont été retirés.</p>
</div>

<div class="doc-section" id="admin-email">
    <h2>Configuration des e-mails</h2>
    <p>Configurez les paramètres SMTP pour l'envoi de notifications par e-mail. Accédez à <span class="menu-label">Admin</span> &rarr; <span class="menu-label">E-mail</span>. Les paramètres comprennent l'hôte SMTP, le port, le nom d'utilisateur, le mot de passe, la méthode de chiffrement (TLS/SSL) et l'adresse de l'expéditeur.</p>
</div>

<div class="doc-section" id="admin-saml">
    <h2>SAML / SSO</h2>
    <p>Configurez l'authentification unique (Single Sign-On) via SAML 2.0. Accédez à <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span>. Cela permet aux utilisateurs de se connecter via le fournisseur d'identité de votre organisation (Okta, Azure AD, etc.).</p>
    <ol class="steps">
        <li>Cochez <span class="field-label">Activer SAML 2.0</span>.</li>
        <li>Remplissez tous les champs obligatoires du fournisseur d'identité (<span class="field-label">IdP Entity ID</span>, <span class="field-label">IdP Single Sign-On URL</span>, <span class="field-label">IdP X.509 Certificate</span>) et du fournisseur de services (<span class="field-label">SP Entity ID</span>, <span class="field-label">SP ACS URL</span>). Le SSO s'active uniquement une fois que <strong>tous</strong> ces champs sont remplis &mdash; un formulaire partiellement complété reste désactivé.</li>
        <li>Cliquez sur <span class="btn-label">Enregistrer</span>.</li>
    </ol>

    <h3>Utiliser la connexion locale et le SSO ensemble</h3>
    <p>Par défaut, activer le SSO ne désactive <strong>pas</strong> le formulaire de nom d'utilisateur/mot de passe local &mdash; la page de connexion affiche un bouton <strong>Se connecter avec SSO</strong> <em>et</em> une option de connexion locale, de sorte que les deux fonctionnent en parallèle. Le comportement est contrôlé par un seul interrupteur de connexion locale :</p>
    <table>
        <tr><th>Mode</th><th>Ce que voient les utilisateurs</th></tr>
        <tr><td><strong>Connexion locale activée</strong> (par défaut)</td><td>Bouton SSO <em>et</em> formulaire de nom d'utilisateur/mot de passe. Utilisez ceci pour faire fonctionner les deux en même temps.</td></tr>
        <tr><td><strong>Connexion locale désactivée</strong> (SSO uniquement)</td><td>Le SSO est le seul chemin pour les utilisateurs normaux. Le compte administrateur <strong>de secours</strong> désigné peut toujours se connecter localement, de sorte qu'un fournisseur d'identité défaillant ne puisse jamais verrouiller tout le monde.</td></tr>
    </table>
    <p>Si SAML n'est pas réellement configuré, l'interrupteur est ignoré et la connexion locale reste toujours disponible (filet de sécurité anti-verrouillage).</p>

    <h3>Secours : autoriser SAML et la connexion locale ensemble (fichier de configuration) <span class="new-badge">Nouveau dans 2.6.2</span></h3>
    <p>L'interrupteur de connexion locale peut être défini de deux façons. Le paramètre du fichier de configuration, lorsqu'il est présent, <strong>prend la priorité sur la valeur de la base de données</strong> &mdash; un contrôle de secours qui ne nécessite pas d'accès à la base de données, vous pouvez ainsi toujours restaurer la connexion locale même si le SSO est défaillant.</p>
    <table>
        <tr><th>Où</th><th>Comment</th></tr>
        <tr><td>Page Admin &rarr; SAML</td><td>Dans la carte <strong>Paramètres de connexion</strong>, cochez ou décochez <span class="field-label">Autoriser la connexion locale par nom d'utilisateur/mot de passe (en plus du SSO)</span> et cliquez sur <span class="btn-label">Enregistrer la configuration SAML</span>. Cela écrit le paramètre <code>local_login_enabled</code> (activé par défaut) &mdash; pas de SQL nécessaire.</td></tr>
        <tr><td>Fichier de configuration (prioritaire si défini)</td><td>Dans <code>config/config.php</code>, sous le bloc <code>auth</code>, définissez <code>'local_login_enabled' =&gt; true</code> pour garder la connexion locale toujours disponible (local + SSO), ou <code>false</code> pour SSO uniquement. Cela <strong>remplace</strong> l'interrupteur ci-dessus ; pendant qu'il est défini, la case à cocher sur la page SAML est affichée en lecture seule. Supprimez la ligne pour la gérer à nouveau depuis l'interface. Redémarrez le conteneur après avoir modifié <code>config.php</code>.</td></tr>
    </table>
    <p>Pour exécuter <strong>à la fois SAML et la connexion locale</strong> sans modifications de la base de données, configurez SAML comme ci-dessus et ajoutez ceci au bloc <code>auth</code> de <code>config/config.php</code>, puis redémarrez le conteneur :</p>
    <pre><code>'auth' =&gt; [
    // ...
    // Secours : true = connexion locale toujours disponible avec le SSO ;
    // false = SSO uniquement (l'admin de secours peut toujours se connecter localement).
    'local_login_enabled' =&gt; true,
],</code></pre>
</div>

<div class="doc-section" id="admin-ai">
    <h2>Intégration IA</h2>
    <p>Activez les fonctionnalités alimentées par l'IA, notamment le raffinement des notes d'évaluation, les suggestions de contrôles, les commentaires sur les fournisseurs, l'analyse de risque FAIR assistée par IA et l'assistance linguistique pour les rapports. Accédez à <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Plateforme IA</span> pour choisir un fournisseur et entrer sa clé API. Une seule plateforme est active à la fois.</p>
    <p>Plateformes IA prises en charge :</p>
    <ul>
        <li><strong>Anthropic (Claude)</strong> <span class="new-badge">Nouveau dans 2.6.2</span> &mdash; se connecte directement à l'API native de Claude (par ex. <code>claude-opus-4-8</code>). Collez votre clé API Anthropic ; le point de terminaison correspond par défaut à l'URL Messages standard. Prend en charge la <strong>recherche web</strong> en direct, de sorte que les alertes de violation et les analyses OSINT sont basées sur des sources actuelles et citées.</li>
        <li><strong>OpenAI (ChatGPT)</strong> <span class="new-badge">Nouveau dans 2.6.2</span> &mdash; se connecte directement à OpenAI (par ex. <code>gpt-4o</code>). Collez votre clé API OpenAI. Pour les analyses de violation &amp; OSINT, il utilise un modèle capable de recherche web (par défaut <code>gpt-4o-search-preview</code>) afin que ces analyses soient basées sur des sources en direct.</li>
        <li><strong>OpenWebUI</strong> &mdash; jeton bearer JWT contre un point de terminaison compatible OpenAI.</li>
        <li><strong>LibreChat</strong> &mdash; authentification par clé API, basé sur des agents ; l'agent gère son propre modèle et son propre échantillonnage.</li>
        <li><strong>Personnalisé</strong> &mdash; collez un modèle d'en-têtes + corps de type curl pour tout autre point de terminaison compatible OpenAI (ou orchestrateur).</li>
    </ul>
    <p><strong>Choisir &amp; charger un modèle :</strong> après avoir saisi et <strong>enregistré</strong> une clé, cliquez sur <span class="btn-label">Charger les modèles</span> sur la carte de cette plateforme pour récupérer la liste des modèles disponibles (OpenWebUI / LibreChat / OpenAI). Pour Anthropic, tapez directement le nom du modèle (par ex. <code>claude-opus-4-8</code>).</p>
    <p><strong>Ancrage des alertes de violation :</strong> les analyseurs de violation &amp; OSINT ont besoin d'un fournisseur capable de rechercher sur le web. <strong>Anthropic (Claude)</strong> et <strong>OpenAI (ChatGPT)</strong> s'ancrent nativement ; OpenWebUI / LibreChat s'ancrent uniquement si l'agent sous-jacent dispose d'une capacité de navigation ; la plateforme personnalisée s'ancre uniquement lorsqu'une URL de recherche web est configurée.</p>
    <p>Le fournisseur IA actif alimente également la <strong>traduction automatique des questions d'évaluation</strong> (voir <a href="#language">Changer votre langue</a>).</p>
</div>

<div class="doc-section" id="admin-updates">
    <h2>Mise à jour de la plateforme <span class="new-badge">Nouveau dans 2.6.2</span></h2>
    <p>Les administrateurs peuvent vérifier et appliquer de nouvelles versions depuis l'intérieur de la plateforme. Accédez à <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span>.</p>
    <ol class="steps">
        <li>La carte <strong>Statut actuel</strong> affiche votre <span class="field-label">Version installée</span> et si une version plus récente est disponible.</li>
        <li>Confirmez que le <span class="field-label">Nom d'hôte du registre</span> est correct (votre registre d'images), puis cliquez sur <span class="btn-label">Vérifier les mises à jour</span>.</li>
        <li>Si une version plus récente est listée, suivez l'action <strong>Mise à niveau</strong> à l'écran pour l'appliquer.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-version.png?v=20260711" alt="Version Management page showing installed version 2.6.2" loading="lazy">
        <figcaption><strong>Admin &rarr; Version.</strong> Ici, la version installée est <strong>v2.6.2</strong> et la plateforme indique qu'elle est à jour. C'est aussi ici que vous confirmez à quelle version ce guide s'applique.</figcaption>
    </figure>
</div>


<!-- ================================================================
     FAQ (SEARCHABLE)
     ================================================================ -->
<div class="doc-section" id="faq">
    <h2>Foire aux questions</h2>
    <p>Tapez un mot-clé ci-dessous pour filtrer instantanément les questions &mdash; par exemple <em>langue</em>, <em>VID</em>, <em>intégration</em>, <em>Grip</em> ou <em>mot de passe</em>.</p>
    <div class="faq-search-wrap">
        <input type="text" id="faqSearch" class="faq-search" placeholder="Rechercher dans la FAQ&hellip;" aria-label="Search frequently asked questions" autocomplete="off">
    </div>
    <p class="faq-count" id="faqCount"></p>
    <div id="faqList">

        <details class="faq-item"><summary>Les utilisateurs peuvent-ils se connecter avec SSO et un mot de passe local en même temps ?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>Oui. Activer SAML/SSO ne désactive <strong>pas</strong> la connexion locale par défaut &mdash; la page de connexion affiche un bouton <strong>Se connecter avec SSO</strong> et une option de nom d'utilisateur/mot de passe local ensemble. Vous contrôlez ceci avec la case à cocher <strong>Autoriser la connexion locale par nom d'utilisateur/mot de passe</strong> sur la page <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span> (laissez-la cochée pour utiliser les deux ; décochez-la pour SSO uniquement, où l'administrateur de secours peut toujours se connecter localement). Pour un contrôle de secours sans base de données, le même paramètre peut être forcé dans <code>config/config.php</code> via <code>'local_login_enabled' =&gt; true</code>, ce qui remplace la case à cocher. Voir <a href="#admin-saml">SAML / SSO</a>.</p></div></details>

        <details class="faq-item"><summary>Le SSO est mal configuré et personne ne peut se connecter. Comment récupérer l'accès ?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>Utilisez le contrôle de secours : dans <code>config/config.php</code>, sous le bloc <code>auth</code>, définissez <code>'local_login_enabled' =&gt; true</code> et redémarrez le conteneur. Cela réactive le formulaire de nom d'utilisateur/mot de passe local quelle que soit la valeur de la base de données, vous permettant de vous connecter et de corriger la configuration SAML. Voir <a href="#admin-saml">SAML / SSO</a>.</p></div></details>

        <details class="faq-item"><summary>Comment changer la langue de la plateforme ?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>Cliquez sur <strong>Profil</strong> (en haut à droite), ouvrez la carte <strong>Préférence de langue</strong>, choisissez votre langue et cliquez sur <strong>Mettre à jour la langue</strong>. Cela ne change que votre propre écran. Voir <a href="#language">Changer votre langue</a>.</p></div></details>

        <details class="faq-item"><summary>Quelles langues sont prises en charge ?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>Anglais, espagnol, italien, ukrainien, chinois (simplifié), hindi, français et portugais. Votre administrateur décide lesquelles apparaissent dans votre liste ; l'anglais est toujours disponible.</p></div></details>

        <details class="faq-item"><summary>J'ai changé ma langue mais certains textes sont encore en anglais. Pourquoi ?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>Plusieurs éléments différents peuvent rester en anglais même après le changement de langue :</p>
            <ul>
                <li><strong>Texte d'interface pas encore traduit.</strong> Les menus, boutons et étiquettes sont traduits partout où une traduction existe pour votre langue. Si une chaîne particulière n'a pas encore été traduite dans votre langue, elle revient à l'anglais plutôt que d'afficher un vide &mdash; vous pouvez donc voir occasionnellement une étiquette en anglais.</li>
                <li><strong>Tout ce qui a été saisi.</strong> Le contenu que vous ou vos fournisseurs saisissez &mdash; noms de fournisseurs, notes, noms de documents importés, réponses en texte libre &mdash; est affiché exactement tel qu'il a été écrit, quelle que soit la langue.</li>
                <li><strong>Questions d'évaluation sans fournisseur IA.</strong> Le texte des <em>questions</em> d'évaluation des fournisseurs n'est traduit automatiquement que lorsque votre administrateur a configuré un fournisseur IA ; sans cela, les questions restent dans la langue dans laquelle elles ont été rédigées. Les valeurs des réponses enregistrées restent toujours en anglais afin que la notation reste cohérente.</li>
                <li><strong>Les e-mails et certains composants tiers</strong> ne sont pas contrôlés par votre paramètre de langue.</li>
            </ul>
            <p>Si vous voyez une étiquette d'interface qui devrait être traduite mais ne l'est pas, informez-en votre administrateur afin que le texte manquant puisse être ajouté.</p></div></details>

        <details class="faq-item"><summary>Pourquoi je ne peux pas soumettre mon fournisseur pour examen ?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>Un fournisseur ne peut être soumis qu'une fois qu'il a complété l'<strong>Intégration par l'approvisionnement</strong> (définie sur <strong>Oui</strong>) et dispose d'un <strong>ID fournisseur (VID)</strong> valide de 4&ndash;8 chiffres. Ouvrez le fournisseur, remplissez les deux champs dans la carte <strong>Informations fournisseur</strong>, enregistrez, puis cliquez sur <strong>Soumettre pour examen</strong>. Voir <a href="#onboarding-workflow">Intégration des fournisseurs</a> et <a href="#troubleshooting">Dépannage</a>.</p></div></details>

        <details class="faq-item"><summary>Qu'est-ce qu'un ID fournisseur (VID) et où puis-je l'obtenir ?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>Le VID est un numéro de 4&ndash;8 chiffres attribué au fournisseur par votre système d'approvisionnement lors de l'intégration du fournisseur. Il relie le fournisseur ici à vos enregistrements d'approvisionnement et financiers. Si vous n'en avez pas, le fournisseur n'a pas encore terminé l'intégration par l'approvisionnement.</p></div></details>

        <details class="faq-item"><summary>Le champ sur mon fournisseur indique « VSU Onboarded », mais le guide dit « Procurement Onboarding ». Lequel est correct ?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>C'est le même champ. Il a été renommé en <strong>« Procurement Onboarding »</strong> plus clair dans la v2.6.2. Si votre écran affiche encore <strong>« VSU Onboarded »</strong>, votre instance n'a pas encore été mise à niveau vers la dernière image v2.6.2 &mdash; le comportement est identique.</p></div></details>

        <details class="faq-item"><summary>Que signifie « Examen IA » pour un fournisseur ?<span class="faq-tag">AI Review</span></summary>
            <div class="faq-body"><p>C'est un statut de révision distinct pour les fournisseurs dont les services utilisent l'IA, afin qu'ils puissent être suivis séparément des examens ordinaires. Un utilisateur Cyber TPRM ou administrateur y déplace un fournisseur via le lien <strong>Forcer l'examen IA</strong>. Voir <a href="#ai-review">Examen IA pour les fournisseurs</a>.</p></div></details>

        <details class="faq-item"><summary>Je ne vois pas le lien « Forcer l'examen IA ». Pourquoi ?<span class="faq-tag">AI Review</span></summary>
            <div class="faq-body"><p>Il n'apparaît que lorsque vous modifiez le fournisseur avec la permission d'approbation, que le champ <strong>Les services utilisent l'IA</strong> du fournisseur est sur <strong>Oui</strong> et que le fournisseur n'est pas déjà en Examen IA.</p></div></details>

        <details class="faq-item"><summary>Qu'est-ce que la page Statut cyber de l'approvisionnement ?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>Une page en langage simple (<strong>TPRM &rarr; Approvisionnement &rarr; Statut cyber</strong>) où l'approvisionnement peut voir quels fournisseurs l'équipe cyber examine et lire les mises à jour datées que l'équipe cyber publie. Voir <a href="#procurement-cyber-status">Statut cyber de l'approvisionnement</a>.</p></div></details>

        <details class="faq-item"><summary>Comment l'approvisionnement reçoit-il des e-mails de mise à jour ?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>Un administrateur active le <strong>Résumé des mises à jour d'approvisionnement</strong> sous <strong>Admin &rarr; Paramètres de messagerie</strong> et ajoute des adresses de destinataires. Il est envoyé par e-mail chaque semaine (par défaut le lundi à 7h00) et peut également être envoyé à la demande.</p></div></details>

        <details class="faq-item"><summary>Qu'est-ce que Grip et que fait-il ici ?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Grip Security découvre les applications SaaS utilisées dans votre organisation. Lorsqu'il est connecté (<strong>Admin &rarr; Shadow SaaS</strong>), la plateforme importe automatiquement ces applications, leur nombre d'utilisateurs, leurs scores de risque et leurs alertes dans votre liste Shadow SaaS. Voir <a href="#shadow-saas-grip">Intégration Grip Shadow SaaS</a>.</p></div></details>

        <details class="faq-item"><summary>Quelle est la différence entre les intégrations Grip et Hero ?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Les deux alimentent la même liste Shadow SaaS depuis un service de découverte tiers &mdash; Grip Security ou HERO Security &mdash; et partagent tous deux le blocage Zscaler et la tâche de réhydratation planifiée. Ils sont <strong>mutuellement exclusifs</strong> : activer l'un désactive l'autre, vous utilisez donc le fournisseur que votre organisation emploie. Voir <a href="#shadow-saas-hero">Intégration Hero Shadow SaaS</a>.</p></div></details>

        <details class="faq-item"><summary>Mon « Test de connexion » Grip a échoué. Que dois-je vérifier ?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Confirmez que le <strong>Serveur (URL de base du tenant)</strong> se termine par <code>/public/saas</code>, que le <strong>Jeton API</strong> est à jour et que votre serveur peut atteindre le point de terminaison Grip. Une erreur de jeton signale <em>« Non autorisé — jeton rejeté »</em> ; une erreur d'URL signale <em>« Point de terminaison introuvable — vérifiez l'URL de base »</em>.</p></div></details>

        <details class="faq-item"><summary>Que fait l'intégration Zscaler ?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Lorsque vous <strong>Refusez</strong> une application non autorisée, la plateforme peut ajouter son domaine web à une catégorie d'URL de blocage dans votre compte Zscaler afin que les utilisateurs ne puissent pas y accéder. Cliquer sur <strong>Autoriser</strong> par la suite supprime le blocage. Voir <a href="#zscaler">Intégration de blocage Zscaler</a>.</p></div></details>

        <details class="faq-item"><summary>Quelle est la différence entre Autoriser, Refuser et Ignorer sur une application Shadow SaaS ?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p><strong>Autoriser</strong> commence l'intégration de l'application en tant que fournisseur ; <strong>Refuser</strong> la marque comme non autorisée (et peut la bloquer dans Zscaler) ; <strong>Ignorer</strong> la cache de la liste. Les applications ignorées restent ignorées même après les synchronisations futures.</p></div></details>

        <details class="faq-item"><summary>Qui peut voir le module GRC ?<span class="faq-tag">Access</span></summary>
            <div class="faq-body"><p>Les utilisateurs des groupes <strong>Administrateur</strong>, <strong>Cyber GRC</strong> ou <strong>Auditeur</strong>. Si vous ne le voyez pas, demandez à votre administrateur de vous ajouter à l'un de ces groupes. Voir <a href="#roles">Rôles utilisateurs &amp; Permissions</a>.</p></div></details>

        <details class="faq-item"><summary>Comment activer l'authentification à deux facteurs (2FA) ?<span class="faq-tag">Account</span></summary>
            <div class="faq-body"><p>Ouvrez <strong>Profil</strong> et utilisez la carte <strong>Authentification à deux facteurs (TOTP)</strong> pour l'activer avec une application d'authentification telle que Google Authenticator ou Microsoft Authenticator.</p></div></details>

        <details class="faq-item"><summary>Puis-je enregistrer ou imprimer cette documentation ?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>Oui. Cliquez sur <strong>Télécharger le PDF</strong> en haut de cette page. Cela produit un document formaté avec une page de couverture, une table des matières et des numéros de page.</p></div></details>

        <details class="faq-item"><summary>Comment savoir quelle version j'utilise ?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>Les administrateurs peuvent vérifier <strong>Admin &rarr; Version</strong>. Ce guide décrit la version <strong>v2.6.2</strong>. Voir <a href="#admin-updates">Mise à jour de la plateforme</a>.</p></div></details>

    </div>
    <p class="faq-no-results" id="faqNoResults">Aucune question ne correspond à votre recherche. Essayez un autre mot-clé.</p>
</div>


<!-- ================================================================
     TROUBLESHOOTING
     ================================================================ -->
<div class="doc-section" id="troubleshooting">
    <h2>Dépannage</h2>

    <h3>Pourquoi l'intégration via l'approvisionnement est importante (la règle VID &amp; Intégration par l'approvisionnement)</h3>
    <p>C'est la chose la plus courante qui empêche un fournisseur d'avancer, il vaut donc la peine de la comprendre. La plateforme <strong>n'autorisera pas la soumission d'un fournisseur pour examen cyber</strong> jusqu'à ce que deux informations d'approvisionnement soient enregistrées sur le fournisseur :</p>
    <ul>
        <li><strong>Intégration par l'approvisionnement = Oui</strong> &mdash; confirmation que le fournisseur a été configuré et validé par le processus d'approvisionnement de votre organisation.</li>
        <li>Un <strong>ID fournisseur (VID)</strong> valide &mdash; le numéro de 4&ndash;8 chiffres attribué au fournisseur par l'approvisionnement.</li>
    </ul>
    <p>Pourquoi imposer cette règle ? Parce que le VID est la clé partagée qui relie ce fournisseur aux enregistrements d'approvisionnement, financiers et contractuels. Si l'équipe cyber examinait et approuvait un fournisseur que l'approvisionnement n'avait jamais intégré, vous vous retrouveriez avec des fournisseurs en double ou « fantômes », un travail de sécurité qui ne peut pas être relié à un bon de commande réel, et des rapports qui ne se recoupent pas. Exiger l'intégration par l'approvisionnement <em>en premier</em> garantit que l'examen de sécurité et l'enregistrement d'approvisionnement pointent vers le même fournisseur réel.</p>
    <div class="callout callout-warning">
        <strong>Correction :</strong> Ouvrez le fournisseur et, dans la carte <strong>Informations fournisseur</strong>, définissez <strong>Intégration par l'approvisionnement</strong> sur <strong>Oui</strong> et entrez l'<strong>ID fournisseur (VID)</strong> de 4&ndash;8 chiffres de votre système d'approvisionnement. Enregistrez, puis cliquez à nouveau sur <strong>Soumettre pour examen</strong>. Si vous n'avez pas encore de VID, le fournisseur n'a pas complété l'intégration par l'approvisionnement &mdash; commencez par là.
    </div>

    <h3>Problèmes courants et comment les résoudre</h3>
    <table class="doc-table">
        <tr><th>Symptôme</th><th>Cause probable &amp; solution</th></tr>
        <tr><td>« Impossible de soumettre : Le fournisseur doit être intégré à VSU avant la soumission&hellip; »</td><td>Le champ <strong>Intégration par l'approvisionnement</strong> n'est pas défini sur <strong>Oui</strong>. Définissez-le sur Oui dans la carte Informations fournisseur et enregistrez.</td></tr>
        <tr><td>« Impossible de soumettre : Un ID fournisseur (VID) valide est requis (4-8 chiffres)&hellip; »</td><td>L'<strong>ID fournisseur</strong> est manquant ou ne comporte pas 4&ndash;8 chiffres. Entrez un VID valide de l'approvisionnement.</td></tr>
        <tr><td>« Seules les demandes en brouillon peuvent être soumises pour examen. »</td><td>Le fournisseur est déjà au-delà du stade Brouillon. Vous ne pouvez soumettre qu'une demande encore en statut <strong>Brouillon</strong>.</td></tr>
        <tr><td>Le bouton <strong>Soumettre pour examen</strong> n'est pas visible</td><td>Il n'apparaît que pour les fournisseurs en état <strong>Brouillon</strong> lorsque vous disposez de la permission de modification.</td></tr>
        <tr><td>« L'examen IA ne peut être forcé que pour les fournisseurs dont les services utilisent l'IA. »</td><td>Définissez <strong>Les services utilisent l'IA</strong> sur <strong>Oui</strong> pour le fournisseur avant de forcer l'examen IA.</td></tr>
        <tr><td>Je ne vois pas le module GRC dans la barre latérale</td><td>Vous devez appartenir au groupe <strong>Administrateur</strong>, <strong>Cyber GRC</strong> ou <strong>Auditeur</strong>. Contactez un administrateur.</td></tr>
        <tr><td>Mon changement de langue n'a pas été conservé</td><td>Assurez-vous d'avoir cliqué sur <strong>Mettre à jour la langue</strong> (pas seulement changé le menu déroulant), et que la langue est activée par votre administrateur.</td></tr>
        <tr><td>Le « Test de connexion » Grip échoue</td><td>Vérifiez que l'URL de base se termine par <code>/public/saas</code> et que le jeton API est valide et à jour.</td></tr>
        <tr><td>Refuser une application Shadow SaaS ne l'a pas bloquée dans Zscaler</td><td>Le blocage Zscaler doit être activé et configuré, et la <strong>Catégorie d'URL</strong> nommée doit déjà exister dans Zscaler.</td></tr>
        <tr><td>L'approvisionnement n'a pas reçu l'e-mail de résumé</td><td>Confirmez que le résumé est activé avec des destinataires sous <strong>Admin &rarr; Paramètres de messagerie</strong>, et que les paramètres SMTP d'<strong>Admin &rarr; E-mail</strong> sont corrects.</td></tr>
        <tr><td>Le bouton Mise à niveau indique « Impossible de récupérer le manifeste »</td><td>Un problème de registre/réseau pour atteindre votre registre d'images. Vérifiez le <strong>Nom d'hôte du registre</strong> sous <strong>Admin &rarr; Version</strong> et que l'hôte peut l'atteindre.</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>Toujours bloqué ?</strong> Notez le message exact affiché à l'écran et la page sur laquelle vous étiez, puis contactez votre administrateur de plateforme. Les administrateurs peuvent consulter <strong>Admin &rarr; Journal d'activité</strong> pour plus de détails.
    </div>
</div>


<!-- ================================================================
     GLOSSARY
     ================================================================ -->
<div class="doc-section" id="glossary">
    <h2>Glossaire</h2>
    <table class="doc-table">
        <tr><th>Terme</th><th>Définition</th></tr>
        <tr><td><strong>ACL</strong></td><td>Liste de contrôle d'accès (Access Control List) &mdash; définit les actions que les utilisateurs d'un groupe peuvent effectuer</td></tr>
        <tr><td><strong>Action Plan</strong></td><td>Un onglet par fournisseur pour planifier des actions de suivi (contacter, envoyer une évaluation, forcer une révision annuelle) avec des dates d'échéance, des responsables et des notes de statut ; déclenché quotidiennement par la tâche Calendrier de remédiation des fournisseurs</td></tr>
        <tr><td><strong>AI Review</strong></td><td>Un statut d'intégration de fournisseur pour les fournisseurs dont les services utilisent l'IA, suivi séparément lors de l'examen</td></tr>
        <tr><td><strong>Assessment</strong></td><td>Une évaluation de conformité à un moment précis utilisant le questionnaire unifié</td></tr>
        <tr><td><strong>CIS Controls</strong></td><td>Center for Internet Security Controls &mdash; un ensemble priorisé de meilleures pratiques de sécurité</td></tr>
        <tr><td><strong>CMMC</strong></td><td>Cybersecurity Maturity Model Certification &mdash; requis pour les sous-traitants du Département de la Défense américain</td></tr>
        <tr><td><strong>Conformity Status</strong></td><td>Si une exigence est Conforme, Partielle, Non conforme, Non applicable ou Non évaluée</td></tr>
        <tr><td><strong>Control</strong></td><td>Une mesure de sécurité spécifique mise en œuvre pour satisfaire aux exigences de conformité</td></tr>
        <tr><td><strong>Crosswalk</strong></td><td>Un mappage entre deux référentiels montrant quelles exigences se recoupent</td></tr>
        <tr><td><strong>CSF</strong></td><td>NIST Cybersecurity Framework &mdash; un référentiel de gestion des risques de cybersécurité largement utilisé</td></tr>
        <tr><td><strong>Custom Field / Custom Data</strong></td><td>Un champ d'intégration propre à l'organisation sans colonne fournisseur standard ; capturé par fournisseur et affiché dans l'onglet Données personnalisées du fournisseur, avec une visibilité par rôle (voir <a href="#custom-onboarding">Champs d'intégration personnalisés</a>)</td></tr>
        <tr><td><strong>Domain</strong></td><td>Une catégorie de questions de sécurité (par ex., Gouvernance, Gestion des identités &amp; des accès)</td></tr>
        <tr><td><strong>Evidence</strong></td><td>Documents, captures d'écran ou fichiers prouvant une affirmation de conformité</td></tr>
        <tr><td><strong>FAIR</strong></td><td>Factor Analysis of Information Risk &mdash; une méthodologie d'analyse quantitative des risques</td></tr>
        <tr><td><strong>FairScore</strong></td><td>Le score de maturité global de la plateforme calculé à partir des réponses aux évaluations</td></tr>
        <tr><td><strong>Finding</strong></td><td>Un problème découvert lors d'un audit (non-conformité, observation, opportunité ou point fort)</td></tr>
        <tr><td><strong>Framework</strong></td><td>Un standard de conformité tel que SOC 2, ISO 27001, PCI DSS, etc.</td></tr>
        <tr><td><strong>GRC</strong></td><td>Gouvernance, Risque et Conformité</td></tr>
        <tr><td><strong>Grip</strong></td><td>Grip Security &mdash; un service qui découvre les applications SaaS en cours d'utilisation ; peut alimenter la liste Shadow SaaS (voir <a href="#shadow-saas-grip">Intégration Grip Shadow SaaS</a>)</td></tr>
        <tr><td><strong>Hero</strong></td><td>HERO Security &mdash; un service alternatif de découverte Shadow SaaS pouvant alimenter la liste Shadow SaaS (mutuellement exclusif avec Grip ; voir <a href="#shadow-saas-hero">Intégration Hero Shadow SaaS</a>)</td></tr>
        <tr><td><strong>HIPAA</strong></td><td>Health Insurance Portability and Accountability Act &mdash; loi américaine sur la protection des données de santé</td></tr>
        <tr><td><strong>ISO 27001</strong></td><td>Norme internationale pour les systèmes de management de la sécurité de l'information</td></tr>
        <tr><td><strong>Maturity Rating</strong></td><td>Un score de 1 à 4 indiquant le niveau de maturité d'une pratique de sécurité (1=Ad Hoc, 4=Optimisé)</td></tr>
        <tr><td><strong>NIST 800-171</strong></td><td>Directives NIST pour la protection des informations non classifiées contrôlées (CUI)</td></tr>
        <tr><td><strong>PCI DSS</strong></td><td>Payment Card Industry Data Security Standard</td></tr>
        <tr><td><strong>PII</strong></td><td>Informations personnellement identifiables (noms, e-mails, adresses, etc.)</td></tr>
        <tr><td><strong>Procurement Onboarding</strong></td><td>Confirmation (Oui/Non) qu'un fournisseur a été configuré via votre processus d'approvisionnement ; requis, avec un VID valide, avant qu'un fournisseur puisse être soumis pour examen. (Libellé « VSU Onboarded » sur les instances mises à niveau depuis des versions antérieures.)</td></tr>
        <tr><td><strong>Requirement</strong></td><td>Une clause spécifique ou un objectif de contrôle au sein d'un référentiel de conformité</td></tr>
        <tr><td><strong>SaaS</strong></td><td>Software as a Service &mdash; applications cloud accessibles via le web</td></tr>
        <tr><td><strong>Shadow SaaS</strong></td><td>Applications SaaS utilisées dans l'organisation qui n'ont jamais été officiellement approuvées ou évaluées</td></tr>
        <tr><td><strong>SOC 2</strong></td><td>Service Organization Control Type 2 &mdash; critères de services de confiance pour les organisations de services</td></tr>
        <tr><td><strong>SPII</strong></td><td>PII sensibles (numéros de sécurité sociale, données financières, dossiers médicaux)</td></tr>
        <tr><td><strong>SRS</strong></td><td>Carte de score des risques de sécurité (Security Risk Scorecard) &mdash; la notation/note de sécurité externe de la plateforme pour un fournisseur</td></tr>
        <tr><td><strong>SSC (SecurityScorecard)</strong></td><td>Une note de sécurité tierce en lettre (A&ndash;F) affichée pour les applications et fournisseurs découverts par Grip lorsque Grip est connecté</td></tr>
        <tr><td><strong>Subprocessor</strong></td><td>Le fournisseur en aval d'un fournisseur ; le même sous-traitant partagé par plusieurs de vos fournisseurs indique une concentration de la chaîne d'approvisionnement (voir <a href="#tprm-fourth-party">Risque de quatrième partie</a>)</td></tr>
        <tr><td><strong>TPRM</strong></td><td>Gestion des risques tiers (Third Party Risk Management)</td></tr>
        <tr><td><strong>Unified Question</strong></td><td>Une question de sécurité unique mappée aux exigences de plusieurs référentiels</td></tr>
        <tr><td><strong>VID</strong></td><td>ID fournisseur (Vendor ID) &mdash; un identifiant de 4&ndash;8 chiffres attribué à un fournisseur par votre système d'approvisionnement</td></tr>
        <tr><td><strong>VSU</strong></td><td>La fonction d'approvisionnement/configuration des fournisseurs ; « intégré à VSU » signifie que le fournisseur a complété l'intégration par l'approvisionnement</td></tr>
        <tr><td><strong>Zscaler</strong></td><td>Un service de sécurité web pouvant bloquer des domaines web ; intégré pour que les applications non autorisées puissent être bloquées lors d'un refus (voir <a href="#zscaler">Blocage Zscaler</a>)</td></tr>
    </table>
</div>


                </div><!-- /.doc-body -->

                <!-- Sticky right-side TOC (screen only) -->
                <nav class="doc-sidebar-toc" id="sidebarToc">
                    <h4>Démarrage</h4>
                    <a href="#overview">Présentation de la plateforme</a>
                    <a href="#navigation">Navigation dans la barre latérale</a>
                    <a href="#roles">Rôles utilisateurs &amp; Permissions</a>
                    <a href="#first-login">Votre première connexion</a>
                    <a href="#whats-new">Nouveautés dans 2.6.2</a>
                    <a href="#language">Changer votre langue</a>
                    <a href="#question-types">Types de questions Téléphone &amp; TVA</a>

                    <h4>GRC — Démarrage rapide</h4>
                    <a href="#grc-overview">Qu'est-ce que le GRC ?</a>
                    <a href="#grc-getting-started">Démarrage</a>
                    <a href="#grc-step1">Étape 1 : Créer une évaluation</a>
                    <a href="#grc-step2">Étape 2 : Répondre aux questions</a>
                    <a href="#grc-step3">Étape 3 : Importer des preuves</a>
                    <a href="#grc-step4">Étape 4 : Voir les scores</a>
                    <a href="#grc-step5">Étape 5 : Générer un rapport</a>

                    <h4>GRC — Fonctionnalités</h4>
                    <a href="#grc-fairscore">Score de maturité CSF</a>
                    <a href="#grc-gaps">Analyse des écarts</a>
                    <a href="#grc-frameworks">Référentiels</a>
                    <a href="#grc-controls">Contrôles internes</a>
                    <a href="#grc-crosswalk">Correspondance des référentiels</a>
                    <a href="#grc-evidence">Bibliothèque de preuves</a>
                    <a href="#grc-policies">Gestion des politiques</a>
                    <a href="#grc-audits">Audits &amp; Constatations</a>
                    <a href="#grc-risks">Registre des risques</a>
                    <a href="#grc-monitors">Moniteurs continus</a>
                    <a href="#grc-tasks">Boîte de réception des tâches</a>
                    <a href="#grc-dashboard">Tableau de bord GRC</a>

                    <h4>Module TPRM</h4>
                    <a href="#tprm-overview">Qu'est-ce que le TPRM ?</a>
                    <a href="#tprm-add-vendor">Ajouter un fournisseur</a>
                    <a href="#tprm-lifecycle">Cycle de vie du fournisseur</a>
                    <a href="#tprm-assessments">Évaluations des fournisseurs</a>
                    <a href="#assessment-forms">Formulaires d'évaluation &amp; Remplissage IA</a>
                    <a href="#tprm-action-plan">Plan d'action fournisseur</a>
                    <a href="#tprm-srs">Carte de score des risques de sécurité</a>
                    <a href="#tprm-fair">Analyse FAIR</a>
                    <a href="#tprm-fourth-party">Risque de quatrième partie</a>
                    <a href="#tprm-shadow-saas">Shadow SaaS</a>

                    <h4>Intégration &amp; Approvisionnement</h4>
                    <a href="#onboarding-workflow">Intégration des fournisseurs</a>
                    <a href="#custom-onboarding">Champs d'intégration personnalisés</a>
                    <a href="#ai-review">Examen IA</a>
                    <a href="#procurement-cyber-status">Statut cyber de l'approvisionnement</a>
                    <a href="#shadow-saas-grip">Intégration Grip Shadow SaaS</a>
                    <a href="#shadow-saas-hero">Intégration Hero Shadow SaaS</a>
                    <a href="#zscaler">Blocage Zscaler</a>
                    <a href="#breach-alerts">Alertes de violation / cyber</a>

                    <h4>Portail d'administration</h4>
                    <a href="#admin-general">Paramètres généraux</a>
                    <a href="#admin-branding">Image de marque &amp; Thème</a>
                    <a href="#admin-users">Gestion des utilisateurs</a>
                    <a href="#admin-acl-groups">Groupes ACL</a>
                    <a href="#admin-templates">Modèles d'évaluation</a>
                    <a href="#admin-email">Configuration des e-mails</a>
                    <a href="#admin-saml">SAML / SSO</a>
                    <a href="#admin-ai">Intégration IA</a>
                    <a href="#admin-backup">Sauvegardes de grandes bases de données</a>
                    <a href="#admin-updates">Mise à jour de la plateforme</a>

                    <h4>Aide &amp; Référence</h4>
                    <a href="#faq">FAQ</a>
                    <a href="#troubleshooting">Dépannage</a>
                    <a href="#glossary">Glossaire</a>
                </nav>

