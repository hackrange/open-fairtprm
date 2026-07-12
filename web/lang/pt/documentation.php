
                <!-- Print-only: Cover Page -->
                <div class="print-cover">
                    <img class="cover-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
                    <div class="cover-rule"></div>
                    <h1>Documentação da Plataforma</h1>
                    <div class="cover-edition">Governança, Risco &amp; Conformidade &bull; Gestão de Risco de Terceiros</div>
                    <div class="cover-version">Versão 2.6.2</div>
                    <div class="cover-rule-bottom"></div>
                    <div class="cover-meta">
                        <strong>Data:</strong> <?php echo date('F j, Y'); ?><br>
                        <strong>Classificação:</strong> Somente para Uso Interno<br>
                        <strong>Preparado por:</strong> Equipe de Administração GRC
                    </div>
                </div>

                <!-- Print-only: Introduction & Purpose -->
                <div class="print-intro">
                    <h2>Introdução</h2>

                    <h3>Objetivo</h3>
                    <p>Este documento fornece documentação abrangente para a Plataforma Fair TPRM &amp; GRC. Ele serve tanto como guia do usuário quanto como manual de referência para todo o pessoal envolvido em gestão de risco de terceiros, governança, avaliação de risco e operações de conformidade.</p>
                    <p>O público-alvo inclui analistas de GRC, responsáveis por conformidade, auditores, equipe de segurança de TI, equipes de compras e administradores de sistema. Quer você esteja conduzindo sua primeira avaliação de conformidade ou gerenciando um programa de auditoria contínuo, este guia fornece as instruções passo a passo de que você precisa.</p>

                    <h3>Escopo</h3>
                    <p>Esta documentação abrange os seguintes módulos e capacidades da plataforma:</p>
                    <ul>
                        <li><strong>Módulo GRC</strong> &mdash; Avaliações de conformidade unificadas em múltiplos frameworks (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, NIST 800-171), gestão de controles internos, coleta de evidências, gestão do ciclo de vida de políticas, gestão de auditorias, registro de riscos, monitoramento contínuo e pontuação de maturidade</li>
                        <li><strong>Módulo TPRM</strong> &mdash; Integração de fornecedores terceirizados, classificação de risco, avaliações de segurança, análise de risco quantitativa (FAIR), pontuação de segurança externa, rastreamento de risco de quarta parte e descoberta de shadow SaaS</li>
                        <li><strong>Portal Admin</strong> &mdash; Configuração do sistema, gestão de usuários e grupos, identidade visual, configurações de e-mail, integração SSO/SAML e configuração de plataforma de IA</li>
                    </ul>

                    <h3>Como Usar Este Guia</h3>
                    <p>Este guia está organizado em quatro partes. <strong>Parte 1 (Primeiros Passos)</strong> cobre a navegação na plataforma, funções de usuário e seu primeiro login. <strong>Parte 2 (Módulo GRC)</strong> fornece um guia detalhado do processo de avaliação de conformidade, começando pela criação da sua primeira avaliação e avançando pela coleta de evidências, pontuação e geração de relatórios. <strong>Parte 3 (Módulo TPRM)</strong> cobre a gestão de risco de fornecedores. <strong>Parte 4 (Portal Admin)</strong> cobre a administração do sistema.</p>
                    <p>Se você é novo na plataforma, comece pela seção <em>Primeiros Passos</em> e depois siga o Guia de Início Rápido GRC de cinco etapas. Cada etapa inclui instruções exatas, clique a clique.</p>

                    <h3>Convenções do Documento</h3>
                    <p>Ao longo deste documento, as seguintes convenções são utilizadas:</p>
                    <ul>
                        <li><strong>Texto em negrito</strong> indica conceitos importantes ou ênfase</li>
                        <li><code>Formatação de código</code> indica valores que você digita ou referências geradas pelo sistema</li>
                        <li>Listas de etapas numeradas indicam procedimentos sequenciais a serem seguidos em ordem</li>
                        <li>Caixas de destaque fornecem dicas, avisos e contexto importante</li>
                    </ul>
                </div>

                <!-- Print-only: Table of Contents (populated by JS) -->
                <div class="print-toc">
                    <h2>Índice</h2>
                    <div id="tocBody"></div>
                </div>

                <!-- Main content body -->
                <div class="doc-body">

                <h1>Documentação da Plataforma</h1>
                <p class="doc-subtitle">Fair TPRM &amp; GRC Platform &mdash; Version 2.6.2 &mdash; Última atualização: <?php echo date('F j, Y'); ?></p>

                <div style="margin-bottom:20px;">
                    <button class="btn-doc" id="printBtn">Baixar PDF</button>
                </div>

<!-- old doc-toc removed — print-toc above now serves as the on-screen TOC -->


<!-- ================================================================
     GETTING STARTED
     ================================================================ -->
<div class="doc-section" id="overview">
    <h2>Visão Geral da Plataforma</h2>
    <p>Esta plataforma fornece dois módulos integrados para gerenciar a postura de segurança da sua organização:</p>
    <ul>
        <li><strong>TPRM (Third Party Risk Management)</strong> &mdash; Rastreie, avalie e pontue seus fornecedores e prestadores de serviços. Entenda o risco de segurança que cada fornecedor representa para a sua organização.</li>
        <li><strong>GRC (Governance, Risk &amp; Compliance)</strong> &mdash; Gerencie frameworks de conformidade (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls e mais), responda a um único questionário unificado que abrange todos os frameworks simultaneamente, rastreie controles internos, faça upload de evidências, gerencie políticas e execute auditorias.</li>
    </ul>
    <p>Os administradores também têm acesso ao <strong>Portal Admin</strong> para configuração do sistema, gestão de usuários, integrações e manutenção.</p>

    <div class="callout callout-success">
        <strong>Conceito-Chave &mdash; Uma Avaliação, Vários Frameworks:</strong> O módulo GRC usa um <em>questionário de avaliação unificado</em> com 146 perguntas em 14 domínios de segurança. Quando você responde a essas perguntas uma vez, a plataforma calcula automaticamente seu percentual de conformidade em relação a cada framework suportado (SOC 2, ISO 27001, PCI DSS, etc.) &mdash; sem trabalho duplicado.
    </div>
</div>

<div class="doc-section" id="navigation">
    <h2>Navegando pela Barra Lateral</h2>
    <p>A barra lateral esquerda é sua principal ferramenta de navegação. Ela está organizada em módulos e seções recolhíveis:</p>
    <ol class="steps">
        <li>No topo da barra lateral você vê o logotipo e o texto de identidade visual da sua empresa.</li>
        <li>Abaixo disso há dois cabeçalhos de módulo recolhíveis: <span class="menu-label">TPRM Module</span> e <span class="menu-label">GRC Module</span>. Clique em qualquer cabeçalho para expandir ou recolher. Seu navegador lembra quais módulos estão abertos.</li>
        <li>Dentro de cada módulo, há <strong>seções</strong> recolhíveis (ex.: "Compliance", "Evidence &amp; Monitoring", "Assessment &amp; Audit"). Clique no título de uma seção para expandi-la e ver os links de navegação internos.</li>
        <li>Na parte inferior da barra lateral você encontrará links utilitários: <span class="menu-label">Dashboard</span>, <span class="menu-label">Profile</span>, <span class="menu-label">Documentation</span> (esta página) e <span class="menu-label">Administration</span> (somente admin).</li>
    </ol>

    <h3>Estrutura da Barra Lateral do Módulo GRC</h3>
    <p>Ao expandir <span class="menu-label">GRC Module</span>, você verá estas seções:</p>
    <table class="doc-table">
        <tr><th>Seção</th><th>Páginas Internas</th><th>O Que Contém</th></tr>
        <tr><td><strong>Compliance</strong></td><td>GRC Dashboard, Frameworks, Internal Controls, Framework Crosswalk</td><td>Visão geral da postura de conformidade, gestão de frameworks, biblioteca de controles e mapeamento entre frameworks</td></tr>
        <tr><td><strong>Evidence &amp; Monitoring</strong></td><td>Evidence Library, Continuous Monitors</td><td>Faça upload e gerencie evidências de conformidade; configure verificações automatizadas de conformidade</td></tr>
        <tr><td><strong>Policy Management</strong></td><td>Policies</td><td>Crie, versione, aprove e publique políticas organizacionais</td></tr>
        <tr><td><strong>Assessment &amp; Audit</strong></td><td>CSF Maturity Score, Assessment Questionnaire, Task Inbox, Audits, Findings, Risk Register</td><td>O questionário de avaliação unificado, painéis de maturidade, auditorias e rastreamento de riscos</td></tr>
    </table>
</div>

<div class="doc-section" id="roles">
    <h2>Funções de Usuário &amp; Permissões</h2>
    <p>Os usuários são atribuídos a um ou mais <strong>Grupos ACL</strong> que determinam o que podem ver e fazer. Um administrador atribui grupos via <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span> &rarr; botão <span class="btn-label">Groups</span>.</p>
    <table class="doc-table">
        <tr><th>Grupo</th><th>O Que Você Pode Fazer</th></tr>
        <tr><td><strong>Administrator</strong></td><td>Acesso total a tudo &mdash; todos os módulos, configurações de admin, gestão de usuários e configuração do sistema</td></tr>
        <tr><td><strong>Cyber TPRM</strong></td><td>Acesso total ao módulo TPRM &mdash; criar/editar/excluir fornecedores, executar avaliações, análise FAIR, pontuação</td></tr>
        <tr><td><strong>Cyber GRC</strong></td><td>Acesso total ao módulo GRC &mdash; gerenciar frameworks, executar avaliações, fazer upload de evidências, gerenciar políticas, executar auditorias, gerenciar riscos</td></tr>
        <tr><td><strong>GRC Contributors</strong></td><td>Acesso GRC limitado &mdash; concluir tarefas atribuídas, fornecer evidências, responder perguntas de avaliação atribuídas</td></tr>
        <tr><td><strong>Auditor</strong></td><td><strong>Acesso somente leitura</strong> aos módulos TPRM e GRC &mdash; pode visualizar tudo, baixar evidências e gerar relatórios, mas não pode criar, editar ou excluir</td></tr>
        <tr><td><strong>Procurement</strong></td><td>Criar e gerenciar solicitações de integração de fornecedores, fazer upload de documentos de fornecedores</td></tr>
        <tr><td><strong>Stakeholder</strong></td><td>Visualizar suas próprias solicitações de fornecedores e responder a tarefas atribuídas a eles</td></tr>
    </table>

    <div class="callout callout-info">
        <strong>Para ver o módulo GRC na barra lateral:</strong> Você deve estar no grupo <strong>Administrator</strong>, <strong>Cyber GRC</strong> ou <strong>Auditor</strong>. Se não vir o GRC Module na barra lateral, peça ao seu administrador para adicioná-lo a um desses grupos.
    </div>
</div>

<div class="doc-section" id="first-login">
    <h2>Seu Primeiro Login</h2>
    <ol class="steps">
        <li>Abra seu navegador web e acesse a URL da plataforma (ex.: <code>https://tprm.yourcompany.com</code>).</li>
        <li>Digite seu <span class="field-label">Nome de Usuário</span> e <span class="field-label">Senha</span> fornecidos pelo seu administrador.</li>
        <li>Se a autenticação de dois fatores (TOTP) estiver habilitada para sua conta, abra seu aplicativo autenticador (Google Authenticator, Microsoft Authenticator, etc.) e insira o código de 6 dígitos quando solicitado.</li>
        <li>Você será direcionado ao <strong>Dashboard</strong>. A barra superior exibe "Bem-vindo(a), [Seu Nome]" com links para Admin (se você for administrador), Perfil e Logout.</li>
        <li>Veja a barra lateral esquerda. Se você está no grupo <strong>Cyber GRC</strong> ou <strong>Administrator</strong>, verá <span class="menu-label">GRC Module</span> na barra lateral. Clique nele para expandir a navegação GRC.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/dashboard.png" alt="The platform dashboard after logging in" loading="lazy">
        <figcaption><strong>O Dashboard.</strong> Após entrar você chega aqui. A barra superior (canto superior direito) tem <strong>Admin</strong>, <strong>Profile</strong> e <strong>Logout</strong>. A barra lateral esquerda é seu menu principal.</figcaption>
    </figure>
</div>


<!-- ================================================================
     WHAT'S NEW IN 2.6.2
     ================================================================ -->
<div class="doc-section" id="whats-new">
    <h2>O Que Há de Novo na Versão 2.6.2</h2>
    <p>A versão 2.6.2 adiciona vários recursos com foco em <strong>integração de fornecedores, colaboração em compras, suporte a vários idiomas e descoberta de shadow SaaS</strong>. Se você usou uma versão anterior, veja o que é novo. Cada item tem um link para seu guia completo mais adiante neste documento.</p>
    <table class="doc-table">
        <tr><th>Novo Recurso</th><th>O Que Faz</th><th>Para Quem É</th></tr>
        <tr><td><strong><a href="#language">Configurações de idioma</a></strong></td><td>Use a plataforma em 8 idiomas. Cada pessoa escolhe seu próprio idioma; os admins escolhem quais idiomas estão disponíveis.</td><td>Todos</td></tr>
        <tr><td><strong><a href="#onboarding-workflow">Integração de Compras &amp; ID de Fornecedor</a></strong></td><td>Um fornecedor deve ser integrado via compras e ter um ID de Fornecedor (VID) válido antes de poder ser enviado para revisão cibernética.</td><td>Procurement, Stakeholders</td></tr>
        <tr><td><strong><a href="#ai-review">Revisão de IA para fornecedores</a></strong></td><td>Um status de revisão dedicado para fornecedores cujos serviços usam IA, além de uma ação "Force AI Review".</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#procurement-cyber-status">Status Cibernético de Compras</a></strong></td><td>Uma página ao vivo mostrando fornecedores em revisão, com um histórico contínuo de atualizações que a equipe cibernética compartilha com compras, além de um resumo semanal por e-mail.</td><td>Procurement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#shadow-saas-grip">Integração Grip Shadow SaaS</a></strong></td><td>Descubra automaticamente aplicativos SaaS usados em toda a sua organização e traga-os para a lista de Shadow SaaS.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#shadow-saas-hero">Integração Hero Shadow SaaS</a></strong></td><td>Um provedor alternativo de Shadow SaaS: descubra fornecedores e problemas de segurança do HERO Security e alimente-os na mesma lista de Shadow SaaS. Grip e Hero são mutuamente exclusivos &mdash; use um ou o outro.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#zscaler">Bloqueio Zscaler</a></strong></td><td>Bloqueie o domínio web de um aplicativo não autorizado diretamente no Zscaler com um clique.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#admin-updates">Atualizações no aplicativo</a></strong></td><td>Verifique em seu registro uma versão mais recente e faça a atualização de dentro do Portal Admin.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#question-types">Tipos de pergunta de telefone &amp; VAT</a></strong></td><td>Novos tipos de campo de avaliação/integração: um número de telefone com seletor de código de país &amp; bandeira (formatado automaticamente) e um número de VAT da UE com dupla entrada e validação gratuita ao vivo contra o serviço oficial EU VIES.</td><td>Todos</td></tr>
        <tr><td><strong><a href="#question-types">Dados de fornecedor &amp; melhorias de busca</a></strong></td><td>Armazene um número de VAT em cada fornecedor (exibido na página do fornecedor com um atalho "Add VAT"), encontre fornecedores por número de VAT na busca rápida e um banner de pontuação de Integração-Compras mais claro.</td><td>Procurement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#admin-backup">Backups de banco de dados grandes</a></strong></td><td>O backup e a restauração agora suportam bancos de dados de vários gigabytes e registros muito grandes sem tempo limite.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#assessment-forms">Formulários de avaliação &amp; Preenchimento Automático por IA</a></strong></td><td>Baixe uma avaliação como um PDF preenchível ou planilha Excel, importe de volta um arquivo concluído e &mdash; com um provedor de IA &mdash; preencha automaticamente as respostas a partir dos certificados atuais do fornecedor.</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#tprm-action-plan">Plano de Ação do Fornecedor</a></strong></td><td>Agende ações de acompanhamento para um fornecedor (contatar, enviar avaliação, forçar revisão anual) com datas de prazo, responsáveis, alertas por e-mail e notas de status.</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#custom-onboarding">Campos de integração personalizados &amp; Dados Personalizados</a></strong></td><td>Capture campos extras específicos da organização em um fornecedor com visibilidade por função, edite-os na aba Custom Data e leia-os na exportação CSV e na API.</td><td>Admins, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#breach-alerts">Alertas de Violação / Cibernéticos</a></strong></td><td>Um feed de violações da cadeia de suprimentos (incluindo incidentes do Grip) com um detalhamento de usuários afetados e ações em massa de reconhecer / falso positivo / excluir.</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#admin-acl-groups">Grupos de controle de acesso personalizados</a></strong></td><td>Crie seus próprios grupos ACL, clone permissões de um grupo existente e defina Leitura vs Leitura/Escrita por módulo. Os grupos fornecidos são protegidos.</td><td>Admins</td></tr>
        <tr><td><strong><a href="#admin-templates">Construtor de Modelos de Avaliação</a></strong></td><td>Novos tipos de pergunta (seleção múltipla, telefone, VAT), instruções de certificado orientadas por modelo, controle de campos por função e modelos desativados ocultos por padrão.</td><td>Admins, Cyber TPRM</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>Como saber qual versão estou usando?</strong> Os administradores podem ir a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span> para ver a versão instalada. Este guia descreve a <strong>v2.6.2</strong>. Consulte <a href="#admin-updates">Atualizando a Plataforma</a>.
    </div>
</div>

<div class="doc-section" id="language">
    <h2>Alterando Seu Idioma <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>A interface da plataforma pode ser exibida em <strong>8 idiomas</strong>. Cada pessoa escolhe seu próprio idioma &mdash; alterar afeta apenas <em>sua</em> tela, não a de mais ninguém. Sua escolha é lembrada a cada vez que você faz login.</p>

    <h3>Idiomas disponíveis</h3>
    <table class="doc-table">
        <tr><th>Idioma</th><th>Exibido no menu como</th></tr>
        <tr><td>Inglês</td><td>English</td></tr>
        <tr><td>Espanhol</td><td>Espa&ntilde;ol</td></tr>
        <tr><td>Italiano</td><td>Italiano</td></tr>
        <tr><td>Ucraniano</td><td>&#1059;&#1082;&#1088;&#1072;&#1111;&#1085;&#1089;&#1100;&#1082;&#1072;</td></tr>
        <tr><td>Chinês (Simplificado)</td><td>&#20013;&#25991;&#65288;&#31616;&#20307;&#65289;</td></tr>
        <tr><td>Hindi</td><td>&#2361;&#2367;&#2344;&#2381;&#2342;&#2368;</td></tr>
        <tr><td>Francês</td><td>Fran&ccedil;ais</td></tr>
        <tr><td>Português</td><td>Portugu&ecirc;s</td></tr>
    </table>
    <p class="callout callout-info" style="margin-top:0;"><strong>Somente os idiomas que seu administrador habilitou aparecerão na sua lista.</strong> O inglês está sempre disponível e não pode ser desativado.</p>

    <h3>Como alterar seu idioma (passo a passo)</h3>
    <ol class="steps">
        <li>Clique em <span class="menu-label">Profile</span> no canto superior direito de qualquer página.</li>
        <li>Na página de Perfil, role para baixo até o cartão <span class="field-label">Language Preference</span>.</li>
        <li>Clique no menu suspenso <span class="field-label">Language</span> e escolha seu idioma. Para voltar ao idioma definido pelo administrador para todos, escolha <strong>System default</strong>.</li>
        <li>Clique em <span class="btn-label">Update Language</span>. A página recarrega e os menus, botões e rótulos agora aparecem no idioma escolhido.</li>
    </ol>
    <figure class="doc-figure narrow">
        <img src="app/docs/profile-language-card.png" alt="Language Preference card on the Profile page" loading="lazy">
        <figcaption><strong>Profile &rarr; Language Preference.</strong> Escolha um idioma e clique em <strong>Update Language</strong>. Escolher <em>System default</em> remove sua preferência pessoal.</figcaption>
    </figure>

    <h3>Para administradores: escolhendo quais idiomas estão disponíveis</h3>
    <p>Os administradores decidem o <strong>idioma padrão</strong> (usado para novos usuários e para a página de login antes de qualquer pessoa entrar) e quais idiomas todos podem escolher.</p>
    <ol class="steps">
        <li>Vá para <span class="menu-label">Admin</span> &rarr; <span class="menu-label">General</span>.</li>
        <li>Encontre o menu suspenso <span class="field-label">Default Language</span> e escolha o padrão para toda a organização.</li>
        <li>Em <span class="field-label">Enabled Languages</span>, marque os idiomas que deseja disponibilizar. (O inglês está sempre marcado e não pode ser desativado.)</li>
        <li>Clique em <span class="btn-label">Save Configuration</span>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-general-language.png" alt="Default Language and Enabled Languages settings in Admin General" loading="lazy">
        <figcaption><strong>Admin &rarr; General.</strong> Defina o <strong>Default Language</strong> e marque os <strong>Enabled Languages</strong> que os usuários podem escolher.</figcaption>
    </figure>

    <div class="callout callout-warning">
        <strong>Bom saber:</strong> A interface é traduzida sempre que existe uma tradução para o seu idioma; uma string que ainda não foi traduzida recorre ao inglês, portanto você ainda pode ver algum rótulo ocasional em inglês. O conteúdo que você ou seus fornecedores digitam (nomes de fornecedores, notas, nomes de arquivos enviados, respostas de texto livre) é sempre exibido exatamente como inserido. As <em>perguntas</em> das avaliações de fornecedores podem ser traduzidas automaticamente para exibição quando um provedor de IA está configurado (veja <a href="#admin-ai">Integração de IA</a>); sem um, elas permanecem no idioma em que foram escritas. Os valores de resposta armazenados sempre permanecem em inglês para que a pontuação e os relatórios permaneçam consistentes em todos os idiomas.
    </div>
</div>


<!-- ================================================================
     PHONE & VAT QUESTION TYPES + VENDOR DATA IMPROVEMENTS
     ================================================================ -->
<div class="doc-section" id="question-types">
    <h2>Tipos de Pergunta Telefone &amp; VAT <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>O <strong>Template Builder</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Assessment Templates</span>) ganha dois novos tipos de pergunta que capturam detalhes de contato e fiscais em um formato limpo e consistente. Eles podem ser usados em qualquer modelo de avaliação ou integração e, como outras perguntas, podem ser mapeados para um campo de fornecedor para que a resposta flua para o registro do fornecedor.</p>

    <h3>Telefone</h3>
    <p>O tipo <strong>Telefone</strong> exibe um seletor de país com uma bandeira e código de discagem ao lado da caixa de número. Os Estados Unidos aparecem em primeiro lugar; todos os outros países seguem em ordem alfabética. Independentemente do formato digitado &mdash; <code>314-444-5544</code>, <code>(314)&nbsp;444-5544</code> ou <code>3144445544</code> &mdash; o número é armazenado em um único formato internacional uniforme (por exemplo, selecionar a bandeira dos EUA e digitar <code>3144445544</code> armazena <code>+13144445544</code>). O formulário padrão de Solicitação de Integração de Fornecedor agora usa este tipo para o número de telefone do contato principal, e o campo de telefone da <strong>atestação</strong> da avaliação também o utiliza.</p>

    <h3>VAT (Número de IVA da UE)</h3>
    <p>O tipo <strong>VAT</strong> é para números de IVA europeus. Para proteger contra erros de digitação, ele deve ser <strong>inserido duas vezes</strong> e as duas entradas devem corresponder antes de ser salvo. O número é armazenado em uma forma consistente (maiúsculas, sem espaços ou pontuação &mdash; por exemplo <code>DE123456789</code>).</p>
    <ul>
        <li><strong>Validação gratuita ao vivo.</strong> Quando você termina de digitar, a plataforma verifica o número no serviço oficial <strong>EU VIES</strong> (o Sistema de Intercâmbio de Informações sobre IVA da Comissão Europeia). O VIES é gratuito, não requer conta e reflete o registro ao vivo de cada estado-membro.</li>
        <li><strong>Consultivo, nunca bloqueante.</strong> Se o VIES não puder confirmar o número, ele ainda é salvo &mdash; um aviso simplesmente pede que você o verifique novamente. Se o VIES estiver momentaneamente lento ou o registro de um país estiver temporariamente indisponível, o número é salvo e você é informado para verificá-lo mais tarde.</li>
        <li><strong>Detalhes sob demanda.</strong> Quando o VIES confirma um número, um botão de informação (&#9432;) aparece ao lado. Ao clicar, abre um painel mostrando o nome e endereço da empresa registrada retornados pelo VIES.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Mapeando VAT para o registro do fornecedor.</strong> Um campo dedicado <code>vat_number</code> está disponível, de modo que uma pergunta VAT mapeada para ele armazena o valor no fornecedor. Ao escolher o tipo de pergunta VAT no Template Builder, esse mapeamento é selecionado automaticamente para você.
    </div>

    <h3>VAT na página do fornecedor</h3>
    <p>O número de VAT do fornecedor é exibido no cartão de <strong>Informações do Fornecedor</strong> na página de integração do fornecedor. Se não houver VAT registrado, um botão <strong>&ldquo;+ Add VAT&rdquo;</strong> aparece para entrar diretamente no modo de edição com o campo VAT em foco.</p>

    <h3>Encontrando fornecedores pelo número de VAT</h3>
    <p>A caixa de <strong>busca rápida</strong> no canto superior direito da plataforma agora também corresponde ao número de VAT, além do nome do fornecedor, domínio e stakeholder. Correspondências diretas com o nome, domínio ou número de VAT de um fornecedor são sempre exibidas primeiro.</p>

    <h2>Banner de pontuação de Integração de Compras <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>Quando o status de <strong>Procurement Onboarding</strong> de um fornecedor está definido como <strong>No</strong>, um banner agora deixa claro que <em>a pontuação automatizada do fornecedor está desativada até que o fornecedor conclua a Integração de Compras</em>. Ele aparece tanto na página de integração do fornecedor quanto abaixo da pergunta de avaliação correspondente, e é atualizado imediatamente conforme a resposta muda.</p>

    <h2>Envio de avaliação: campos obrigatórios verificados primeiro <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>Quando um fornecedor clica em <strong>Submit</strong> em uma avaliação, a plataforma agora verifica se cada pergunta obrigatória está respondida <em>antes</em> de solicitar os detalhes de atestação do remetente. Anteriormente, uma resposta faltando só era reportada após o preenchimento da atestação, obrigando a preenchê-la novamente.</p>
</div>

<!-- ================================================================
     LARGE DATABASE BACKUPS
     ================================================================ -->
<div class="doc-section" id="admin-backup">
    <h2>Backups &amp; Restaurações de Banco de Dados Grandes <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>O backup e a restauração (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Backup</span>) agora lidam com <strong>bancos de dados de vários gigabytes</strong> e registros individuais chegando a <strong>1&nbsp;GB</strong> sem que a operação seja interrompida por um tempo limite ou falta de memória. Em segundo plano, o limite de pacotes do banco de dados, os tempos limite de rede, o tamanho de upload e os limites de tempo de requisição foram todos aumentados para acomodar dados muito grandes.</p>
    <div class="callout callout-info">
        <strong>Para bancos de dados muito grandes:</strong> Um backup ou restauração de um arquivo de vários gigabytes pode demorar &mdash; deixe a página aberta até que termine. Conjuntos de dados extremamente grandes (dezenas de gigabytes) são melhor restaurados pela linha de comando do servidor.
    </div>
</div>


<!-- ================================================================
     GRC MODULE - COMPREHENSIVE DOCUMENTATION
     ================================================================ -->
<div class="doc-section" id="grc-overview">
    <h2>Módulo GRC: O Que é Governança, Risco &amp; Conformidade?</h2>
    <p><strong>GRC</strong> significa <strong>Governance, Risk, and Compliance</strong> (Governança, Risco e Conformidade). É a prática de garantir que sua organização cumpra os requisitos regulatórios, siga as melhores práticas de segurança, gerencie riscos e possa comprovar conformidade a auditores e reguladores.</p>

    <p>O módulo GRC ajuda você a:</p>
    <ul>
        <li><strong>Avaliar sua maturidade de segurança</strong> usando um único questionário unificado que mapeia para múltiplos frameworks de conformidade simultaneamente</li>
        <li><strong>Rastrear conformidade</strong> em relação a SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls e mais</li>
        <li><strong>Gerenciar controles internos</strong> &mdash; documentar as medidas de segurança que sua organização implementou</li>
        <li><strong>Coletar e armazenar evidências</strong> &mdash; fazer upload de capturas de tela, exportações de configuração, documentos de política e certificados que comprovam conformidade</li>
        <li><strong>Gerenciar políticas</strong> &mdash; criar, versionar, aprovar e publicar políticas de segurança organizacionais</li>
        <li><strong>Executar auditorias</strong> &mdash; planejar auditorias, registrar constatações, atribuir remediação e rastrear o encerramento</li>
        <li><strong>Rastrear riscos</strong> &mdash; manter um registro de riscos com pontuação de probabilidade/impacto e planos de tratamento</li>
        <li><strong>Monitorar continuamente</strong> &mdash; configurar verificações automatizadas que validam controles de conformidade em um cronograma</li>
    </ul>

    <div class="callout callout-warning">
        <strong>Conceito Importante &mdash; Perguntas Unificadas:</strong> A plataforma contém <strong>146 perguntas de segurança unificadas</strong> organizadas em <strong>14 domínios de segurança</strong> (Governança, Gestão de Identidade &amp; Acesso, Segurança de Dados, Segurança de Rede, etc.). Cada pergunta é pré-mapeada para requisitos específicos em múltiplos frameworks de conformidade. Quando você responde a uma pergunta uma vez, a resposta se aplica automaticamente a cada framework para o qual essa pergunta está mapeada. Isso elimina a necessidade de responder à mesma pergunta separadamente para SOC 2, ISO 27001 e PCI DSS.
    </div>
</div>


<div class="doc-section" id="grc-getting-started">
    <h2>Primeiros Passos com GRC &mdash; Guia de Início Rápido</h2>
    <p>Se você é totalmente novo no módulo GRC, siga estas etapas em ordem. Ao final, você terá uma avaliação de conformidade completa com pontuações em todos os frameworks.</p>

    <div class="callout callout-info">
        <strong>Pré-requisitos:</strong><br>
        &bull; Você deve estar logado como usuário no grupo <strong>Administrator</strong> ou <strong>Cyber GRC</strong><br>
        &bull; Você deve conseguir ver <span class="menu-label">GRC Module</span> na barra lateral esquerda<br>
        &bull; Se não o vir, peça ao seu administrador para atribuí-lo ao grupo Cyber GRC (Admin &rarr; Users &rarr; clique no botão Groups ao lado do seu nome &rarr; marque "Cyber GRC" &rarr; Save)
    </div>

    <p>O fluxo de trabalho recomendado é:</p>
    <ol>
        <li><strong>Criar uma Avaliação</strong> &mdash; Define o escopo e o propósito da sua revisão de conformidade</li>
        <li><strong>Responder às Perguntas</strong> &mdash; Trabalhe nas 146 perguntas unificadas, avaliando seu nível de maturidade em cada uma</li>
        <li><strong>Fazer Upload de Evidências</strong> &mdash; Anexe documentos, capturas de tela e arquivos que comprovem suas respostas</li>
        <li><strong>Ver Suas Pontuações</strong> &mdash; Verifique seus percentuais de conformidade na página Frameworks</li>
        <li><strong>Gerar Relatórios</strong> &mdash; Crie relatórios detalhados de conformidade por framework para auditores</li>
    </ol>
    <p>Cada etapa é explicada em detalhes abaixo.</p>
</div>


<div class="doc-section" id="grc-step1">
    <h2>Etapa 1: Criar Sua Primeira Avaliação</h2>
    <p>Uma <strong>Avaliação</strong> é uma revisão de conformidade da sua organização. Representa uma avaliação em um ponto no tempo em que você responde a perguntas de segurança, registra classificações de maturidade e coleta evidências. Pense nela como uma "fotografia da conformidade".</p>

    <h3>Como Criar uma Nova Avaliação</h3>
    <ol class="steps">
        <li>Na barra lateral esquerda, clique em <span class="menu-label">GRC Module</span> para expandi-lo.</li>
        <li>Clique na seção <span class="menu-label">Assessment &amp; Audit</span> para expandi-la.</li>
        <li>Clique em <span class="menu-label">Assessment Questionnaire</span>. Isso abre a página principal de avaliação.</li>
        <li>No topo da página, você verá um botão <span class="btn-label">+ New Assessment</span>. Clique nele.</li>
        <li>Um formulário aparecerá. Preencha os seguintes campos:
            <ul>
                <li><span class="field-label">Title</span> &mdash; Dê à sua avaliação um nome descritivo. Exemplo: <code>2026 Annual Security Assessment - ACME Corp</code></li>
                <li><span class="field-label">Assessment Type</span> &mdash; Selecione o tipo de avaliação:
                    <ul>
                        <li><strong>Initial</strong> &mdash; Sua primeira avaliação (recomendada para novos usuários)</li>
                        <li><strong>Periodic</strong> &mdash; Uma avaliação recorrente regular (ex.: revisão anual)</li>
                        <li><strong>Targeted</strong> &mdash; Uma avaliação focada em uma área específica</li>
                        <li><strong>Pre-Audit</strong> &mdash; Preparação antes de uma auditoria formal</li>
                        <li><strong>Certification</strong> &mdash; Avaliação para fins de certificação (ex.: SOC 2 Type II)</li>
                    </ul>
                </li>
                <li><span class="field-label">Scope</span> &mdash; Selecione ou descreva o escopo organizacional. Define qual parte da sua organização está sendo avaliada (ex.: "Todos os sistemas de TI" ou "Infraestrutura em Nuvem").</li>
                <li><span class="field-label">Lead Auditor</span> &mdash; Selecione a pessoa que lidera esta avaliação. O menu suspenso mostra apenas usuários nos grupos Administrator ou Cyber GRC.</li>
                <li><span class="field-label">Planned Start Date</span> &mdash; Quando você planeja iniciar a avaliação.</li>
                <li><span class="field-label">Planned End Date</span> &mdash; Sua data-alvo de conclusão.</li>
            </ul>
        </li>
        <li>Clique em <span class="btn-label">Create Assessment</span>.</li>
        <li>Sua nova avaliação é criada com o status <span class="status-label">Draft</span>. Você pode começar a responder às perguntas.</li>
    </ol>

    <div class="example-box">
        <strong>Exemplo:</strong> Você está conduzindo a primeira revisão anual de segurança da sua organização.<br><br>
        &bull; Título: <code>2026 Annual Security Assessment</code><br>
        &bull; Tipo: <code>Initial</code><br>
        &bull; Escopo: <code>All Corporate IT Systems</code><br>
        &bull; Auditor Principal: <code>Jane Smith</code><br>
        &bull; Data de Início: <code>March 1, 2026</code><br>
        &bull; Data de Término: <code>April 30, 2026</code>
    </div>

    <h3>Status de Avaliação</h3>
    <table class="doc-table">
        <tr><th>Status</th><th>Significado</th></tr>
        <tr><td><span class="status-label">Draft</span></td><td>A avaliação foi criada, mas o trabalho ainda não começou. As perguntas podem ser respondidas.</td></tr>
        <tr><td><span class="status-label">In Progress</span></td><td>Avaliação ativa &mdash; os membros da equipe estão respondendo às perguntas e fazendo upload de evidências.</td></tr>
        <tr><td><span class="status-label">Under Review</span></td><td>Todas as perguntas respondidas &mdash; um auditor principal ou validador está revisando as respostas.</td></tr>
        <tr><td><span class="status-label">Completed</span></td><td>A avaliação está concluída e finalizada. As respostas estão bloqueadas.</td></tr>
        <tr><td><span class="status-label">Archived</span></td><td>Avaliação histórica mantida para registros. Não mais ativa.</td></tr>
    </table>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment.png" alt="Assessment list on the Assessment Questionnaire page" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Assessment Questionnaire.</strong> Cada avaliação é listada com sua referência, título, tipo, status, auditor principal, pontuação CSF atual e % de conformidade, e data planejada. Use <span class="btn-label">+ New Assessment</span> para iniciar uma, ou <span class="btn-label">Open</span> para continuar respondendo a uma existente. As guias de status na parte superior filtram a lista.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step2">
    <h2>Etapa 2: Responder às Perguntas da Avaliação</h2>
    <p>Depois de criar uma avaliação, você precisa responder às 146 perguntas de segurança unificadas. Cada pergunta pertence a um dos 14 domínios de segurança.</p>

    <h3>Os 14 Domínios de Segurança</h3>
    <table class="doc-table">
        <tr><th>Código</th><th>Nome do Domínio</th><th>Perguntas</th><th>O Que Abrange</th></tr>
        <tr><td><code>GOV</code></td><td>Governance &amp; Leadership</td><td>12</td><td>Liderança do programa de segurança, estratégia, orçamento, relatórios ao conselho</td></tr>
        <tr><td><code>IAM</code></td><td>Identity &amp; Access Management</td><td>14</td><td>Contas de usuário, autenticação, controles de acesso, acesso privilegiado</td></tr>
        <tr><td><code>DSP</code></td><td>Data Security &amp; Privacy</td><td>12</td><td>Classificação de dados, criptografia, privacidade, prevenção de perda de dados</td></tr>
        <tr><td><code>EPS</code></td><td>Endpoint &amp; Platform Security</td><td>10</td><td>Laptops, servidores, dispositivos móveis, patching, EDR</td></tr>
        <tr><td><code>NET</code></td><td>Network Security</td><td>11</td><td>Firewalls, segmentação, VPN, segurança DNS, Wi-Fi</td></tr>
        <tr><td><code>APS</code></td><td>Application Security</td><td>10</td><td>Desenvolvimento seguro, revisões de código, segurança de API, WAF</td></tr>
        <tr><td><code>OPS</code></td><td>Security Operations</td><td>12</td><td>SIEM, logging, monitoramento, varredura de vulnerabilidades, SOC</td></tr>
        <tr><td><code>INC</code></td><td>Incident Management</td><td>10</td><td>Planos de resposta a incidentes, exercícios de tabletop, notificação de violação</td></tr>
        <tr><td><code>SCM</code></td><td>Supply Chain &amp; Third Party</td><td>10</td><td>Gestão de fornecedores, risco da cadeia de suprimentos, contratos</td></tr>
        <tr><td><code>PHY</code></td><td>Physical &amp; Environmental</td><td>8</td><td>Centros de dados, controle de acesso por crachá, CCTV, controles ambientais</td></tr>
        <tr><td><code>HRS</code></td><td>Human Resources Security</td><td>10</td><td>Verificações de antecedentes, treinamento de segurança, procedimentos de desligamento</td></tr>
        <tr><td><code>BCP</code></td><td>Business Continuity</td><td>10</td><td>Backup, recuperação de desastres, testes de BCP, RTO/RPO</td></tr>
        <tr><td><code>CRY</code></td><td>Cryptography &amp; Key Management</td><td>8</td><td>Padrões de criptografia, rotação de chaves, gestão de certificados</td></tr>
        <tr><td><code>CMP</code></td><td>Compliance &amp; Assurance</td><td>9</td><td>Conformidade regulatória, auditoria interna, prontidão para auditoria externa</td></tr>
    </table>

    <h3>Como Responder às Perguntas</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Assessment Questionnaire</span>.</li>
        <li>Se você tiver várias avaliações, selecione a correta no menu suspenso no topo da página.</li>
        <li>Você verá os 14 domínios de segurança listados. Clique no nome de um domínio (ex.: <strong>GOV - Governance &amp; Leadership</strong>) para expandi-lo e ver suas perguntas.</li>
        <li>Para cada pergunta, você precisa fornecer duas informações:
            <ul>
                <li><span class="field-label">Maturity Rating</span> (1-4) &mdash; Quão madura é a implementação deste controle pela sua organização?
                    <ul>
                        <li><strong>1 &mdash; Inicial/Ad Hoc:</strong> Sem processo formal. Feito de forma inconsistente ou inexistente.</li>
                        <li><strong>2 &mdash; Em Desenvolvimento:</strong> Alguns processos existem, mas não são seguidos de forma consistente. Parcialmente documentado.</li>
                        <li><strong>3 &mdash; Definido:</strong> Processos formais e documentados estão em vigor e são seguidos de forma consistente.</li>
                        <li><strong>4 &mdash; Gerenciado/Otimizado:</strong> Os processos são medidos, monitorados e continuamente melhorados.</li>
                    </ul>
                </li>
                <li><span class="field-label">Conformity Status</span> &mdash; Seu status de conformidade para esta pergunta:
                    <ul>
                        <li><strong>Conforming</strong> &mdash; Totalmente implementado e atende ao requisito</li>
                        <li><strong>Partial</strong> &mdash; Parcialmente implementado; algumas lacunas permanecem</li>
                        <li><strong>Non-Conforming</strong> &mdash; Não implementado ou não atende ao requisito</li>
                        <li><strong>Not Applicable</strong> &mdash; Esta pergunta não se aplica à sua organização</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li>Opcionalmente, adicione <span class="field-label">Notes</span> para explicar sua resposta. Isso é altamente recomendado &mdash; os auditores vão querer ver seu raciocínio.</li>
        <li>Suas respostas são <strong>salvas automaticamente</strong> enquanto você trabalha. Não é necessário clicar em um botão de salvar.</li>
        <li>Continue respondendo às perguntas em todos os 14 domínios. Não é necessário concluir tudo em uma sessão &mdash; volte a qualquer momento para retomar.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Dica &mdash; Maturidade influencia Conformidade:</strong> Quando você define uma classificação de maturidade, o sistema pode derivar automaticamente o status de conformidade: Maturidade 3-4 = Conforming, Maturidade 2 = Partial, Maturidade 1 = Non-Conforming. Você pode substituir isso se necessário.
    </div>

    <div class="callout callout-warning">
        <strong>Importante:</strong> Cada pergunta que você responde mapeia para requisitos em múltiplos frameworks. Por exemplo, responder a uma pergunta sobre "Autenticação Multifator" (no domínio IAM) atualiza simultaneamente suas pontuações de conformidade para SOC 2, ISO 27001, PCI DSS, NIST CSF e CMMC. Você nunca precisa responder ao mesmo conceito duas vezes.
    </div>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment-questions.png" alt="Answering questions in the assessment questionnaire" loading="lazy">
        <figcaption><strong>Respondendo ao questionário.</strong> O cabeçalho rastreia o <em>Progresso</em>, a <em>Maturidade CSF</em> ao vivo e a <em>Conformidade</em> enquanto você trabalha. As abas de domínio (GOV, IAM, DSP, &hellip;) mostram a pontuação atual daquele domínio; clique em uma para ir às suas perguntas. Para cada pergunta você define uma classificação de <strong>Maturidade</strong> (1&ndash;4 ou N/A) e um status de <strong>Conformidade</strong> &mdash; as respostas são salvas automaticamente. Use <span class="btn-label">Show Unanswered Questions</span> para encontrar o que falta.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step3">
    <h2>Etapa 3: Fazer Upload de Evidências</h2>
    <p>As evidências provam que suas respostas são precisas. Os auditores vão esperar ver evidências para cada afirmação de conformidade. As evidências podem incluir capturas de tela, exportações de configuração, documentos de política, logs de auditoria, certificados e muito mais.</p>

    <h3>Como Fazer Upload de Evidências Durante uma Avaliação</h3>
    <ol class="steps">
        <li>Ao responder a uma pergunta no <span class="menu-label">Assessment Questionnaire</span>, procure a seção <strong>Evidence</strong> abaixo da área de resposta da pergunta.</li>
        <li>Clique em <span class="btn-label">Upload Evidence</span> ou no ícone de anexo.</li>
        <li>Selecione um arquivo do seu computador. Os tipos suportados incluem PDF, imagens (PNG, JPG), documentos Word, planilhas Excel e arquivos de texto.</li>
        <li>Dê à evidência um <span class="field-label">Title</span> descritivo (ex.: "Captura de Tela da Configuração MFA - Console Admin do Okta").</li>
        <li>A evidência é automaticamente vinculada à pergunta de avaliação atual.</li>
        <li>Você pode fazer upload de vários arquivos de evidência por pergunta.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Segurança:</strong> Todos os arquivos de evidência enviados são criptografados (AES-256-CBC) antes de serem armazenados no banco de dados. Ao baixar evidências, elas são descriptografadas em tempo real. Isso garante que documentos de conformidade sensíveis sejam protegidos em repouso.
    </div>

    <h3>Biblioteca de Evidências</h3>
    <p>Você também pode gerenciar evidências separadamente via <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Evidence Library</span>. Esta página mostra todas as evidências em todas as avaliações e controles, com filtragem por tipo, status e data de expiração.</p>
</div>


<div class="doc-section" id="grc-step4">
    <h2>Etapa 4: Ver Suas Pontuações de Conformidade</h2>
    <p>À medida que você responde às perguntas, a plataforma calcula seu percentual de conformidade para cada framework em tempo real.</p>

    <h3>Visualizando Pontuações na Página de Frameworks</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>.</li>
        <li>No topo da página, você verá um menu suspenso <span class="field-label">Assessment</span>. Selecione a avaliação para a qual deseja ver as pontuações. Por padrão, a avaliação mais recente é selecionada.</li>
        <li>Abaixo do menu suspenso, você verá cartões de framework &mdash; um para cada framework de conformidade que tem perguntas mapeadas. Cada cartão mostra:
            <ul>
                <li>Um <strong>gráfico de rosca</strong> mostrando o percentual geral de conformidade (ex.: 75%)</li>
                <li>O <strong>código e nome do framework</strong> (ex.: "SOC2 &mdash; SOC 2 Type II")</li>
                <li>A pontuação de <strong>Maturidade Média</strong> (se existirem dados de maturidade, exibida como ex.: "3.50 / 4.00")</li>
                <li>Contagens de métricas: <strong>Conforming</strong>, <strong>Partial</strong>, <strong>Non-Conforming</strong> e <strong>Total Mapped</strong></li>
            </ul>
        </li>
        <li>Clique em qualquer cartão de framework para abrir o <strong>Compliance Report</strong> detalhado daquele framework.</li>
    </ol>

    <h3>Cálculo do Percentual de Conformidade</h3>
    <p>O percentual de conformidade é calculado como:</p>
    <div class="example-box">
        <strong>Fórmula:</strong> <code>(Conforming + Partial &times; 0.5) &divide; Applicable Requirements &times; 100</code><br><br>
        &bull; Requisitos <strong>Conforming</strong> contam como 100% concluídos<br>
        &bull; Requisitos <strong>Partial</strong> contam como 50% concluídos<br>
        &bull; Requisitos <strong>Not Applicable</strong> são excluídos do cálculo<br>
        &bull; Requisitos <strong>Non-Conforming</strong> e <strong>Not Assessed</strong> contam como 0%
    </div>

    <h3>Frameworks Suportados Atualmente</h3>
    <table class="doc-table">
        <tr><th>Framework</th><th>Versão</th><th>Perguntas Mapeadas</th></tr>
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
    <h2>Etapa 5: Gerar um Relatório de Conformidade de Framework</h2>
    <p>Depois de responder às perguntas, você pode gerar um relatório detalhado de conformidade para qualquer framework. Este relatório é adequado para compartilhar com auditores, reguladores ou gestores.</p>

    <h3>Como Gerar um Relatório</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>.</li>
        <li>Selecione sua avaliação no menu suspenso <span class="field-label">Assessment</span> no topo.</li>
        <li>Clique no cartão do framework sobre o qual deseja gerar o relatório (ex.: "SOC2 &mdash; SOC 2 Type II").</li>
        <li>A página <strong>Framework Compliance Report</strong> abre, mostrando:
            <ul>
                <li><strong>Cabeçalho do Relatório</strong> &mdash; Nome do framework, título da avaliação, tipo, status, escopo, auditor principal, datas e percentual geral de conformidade</li>
                <li><strong>Estatísticas Resumidas</strong> &mdash; Cartões clicáveis mostrando Total de Requisitos, Conforming, Partial, Non-Conforming, Not Assessed e contagens de N/A</li>
                <li><strong>Cartões de Requisitos</strong> &mdash; Um cartão por requisito do framework, mostrando a referência do requisito, título, distintivo de status e todas as perguntas mapeadas com suas respostas</li>
            </ul>
        </li>
        <li>Para <strong>filtrar requisitos por status</strong>, clique em qualquer um dos cartões de estatísticas resumidas no topo. Por exemplo, clique em <strong>Non-Conforming</strong> para mostrar apenas os requisitos não conformes. Clique novamente (ou clique em "Total Requirements") para mostrar todos.</li>
        <li>Para <strong>imprimir o relatório</strong>, clique no botão <span class="btn-label">Print Report</span> no topo. O diálogo de impressão do seu navegador abrirá. Você pode imprimir em papel ou selecionar "Salvar como PDF" para criar um arquivo PDF.</li>
    </ol>

    <h3>O Que Cada Cartão de Requisito Mostra</h3>
    <p>Para cada requisito no relatório, você verá:</p>
    <ul>
        <li><strong>Referência do Requisito</strong> &mdash; O número de referência oficial (ex.: "CC6.1" para SOC 2)</li>
        <li><strong>Título do Requisito</strong> &mdash; O que o requisito diz</li>
        <li><strong>Distintivo de Status</strong> &mdash; Codificado por cores: verde (Conforming), âmbar (Partial), vermelho (Non-Conforming), cinza (Not Assessed / N/A)</li>
        <li><strong>Perguntas Mapeadas</strong> &mdash; Cada pergunta que mapeia para este requisito, mostrando:
            <ul>
                <li>Referência e texto da pergunta</li>
                <li>Classificação de maturidade (1-4) com uma barra visual</li>
                <li>Status de conformidade</li>
                <li>Status de validação (Pending, Validated, Rejected, Needs Review)</li>
                <li>Nome do avaliador e data</li>
                <li>Força do mapeamento (Exact, Strong, Partial, Related)</li>
                <li>Notas do avaliador</li>
                <li>Notas de validação</li>
                <li>Anexos de evidência (com links de download)</li>
            </ul>
        </li>
    </ul>
</div>


<div class="doc-section" id="grc-fairscore">
    <h2>Painel de Pontuação de Maturidade CSF</h2>
    <p>A página <strong>CSF Maturity Score</strong> fornece um painel visual mostrando a maturidade da sua organização em todos os 14 domínios de segurança, alinhada ao NIST Cybersecurity Framework.</p>

    <h3>Como Acessar</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">CSF Maturity Score</span>.</li>
        <li>Se você tiver várias avaliações, selecione a desejada no menu suspenso.</li>
        <li>A página mostra:
            <ul>
                <li><strong>Overall FAIR Score</strong> &mdash; Uma pontuação de maturidade média ponderada em todos os domínios</li>
                <li><strong>Radar Chart</strong> &mdash; Um gráfico visual de aranha/radar plotando suas pontuações em todos os 14 domínios</li>
                <li><strong>Cartões de Pontuação por Domínio</strong> &mdash; Cartões individuais para cada domínio mostrando maturidade média, perguntas respondidas e distribuição de conformidade</li>
                <li><strong>Barras de Conformidade por Framework</strong> &mdash; Barras horizontais mostrando percentuais de conformidade por framework</li>
                <li><strong>Resumo de Análise de Lacunas</strong> &mdash; Domínios onde as pontuações estão abaixo da meta</li>
            </ul>
        </li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grc-fairscore.png" alt="CSF Maturity Score dashboard with radar chart" loading="lazy">
        <figcaption><strong>GRC Module &rarr; CSF Maturity Score.</strong> Os quatro blocos principais &mdash; <em>CSF Maturity Score</em> (escala 1&ndash;4), <em>Compliance Rate</em>, <em>Questions Answered</em> e <em>Gaps Found</em> &mdash; resumem sua postura de relance. O <strong>Security Domain Maturity Radar</strong> plota todos os 14 domínios, e a lista à direita fornece a pontuação média exata de cada domínio. Selecione a avaliação desejada no menu suspenso no topo.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-gaps">
    <h2>Análise de Lacunas</h2>
    <p>A página de <strong>Gap Analysis</strong> reúne todas as fraquezas encontradas durante uma avaliação &mdash; cada pergunta respondida como <strong>Non-Conforming</strong> ou <strong>Partial</strong> &mdash; em uma lista de trabalho priorizada. Ela responde à pergunta "onde estamos aquém, e o que cada deficiência afeta?"</p>

    <h3>Como Acessar</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Gaps</span>.</li>
        <li>Selecione a avaliação que deseja analisar no menu suspenso <span class="field-label">Assessment</span>.</li>
    </ol>

    <h3>O Que a Página Mostra</h3>
    <p>Quatro blocos de resumo no topo contam seu <strong>Total de Lacunas</strong>, <strong>Non-Conforming</strong>, <strong>Partial</strong> e lacunas <strong>Com Risco Vinculado</strong>. Abaixo deles, cada lacuna é listada como uma linha com:</p>
    <ul>
        <li><strong>Gravidade</strong> &mdash; um distintivo: <em>Non-Conforming</em> (vermelho) ou <em>Partial</em> (âmbar).</li>
        <li><strong>Domínio</strong> e <strong>Ref</strong> &mdash; o domínio de segurança e a referência exata da pergunta (ex.: <code>GOV-08</code>).</li>
        <li><strong>Constatação</strong> &mdash; o texto da pergunta descrevendo o que está faltando.</li>
        <li><strong>Impacto no Framework</strong> &mdash; distintivos para cada requisito de framework que esta lacuna afeta, para que você veja de relance se uma única correção melhora SOC 2, ISO 27001, PCI DSS e mais ao mesmo tempo.</li>
        <li><strong>Risco</strong> &mdash; se um risco foi registrado para esta lacuna, e uma ação <span class="btn-label">View</span> para abrir o detalhe completo.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/grc-gaps.png" alt="Gap Analysis page listing non-conforming and partial findings" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Gaps.</strong> Cada resposta não conforme ou parcial torna-se uma lacuna. A coluna <strong>Framework Impact</strong> mostra quais requisitos em cada framework a lacuna toca &mdash; fechar uma lacuna pode elevar vários frameworks ao mesmo tempo.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-frameworks">
    <h2>Página de Frameworks</h2>
    <p>A página de <strong>Frameworks</strong> é seu hub central para visualizar o status de conformidade em todos os frameworks suportados. Ela exibe dados de conformidade orientados por avaliação.</p>

    <h3>Como Usar</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>.</li>
        <li>Selecione uma avaliação no menu suspenso <span class="field-label">Assessment</span>. A página usa por padrão sua avaliação mais recente.</li>
        <li>A página exibe cartões de framework em uma grade. Apenas frameworks com perguntas mapeadas aparecem. Cada cartão mostra percentual de conformidade, pontuação de maturidade e contagens de métricas.</li>
        <li>Clique em um cartão de framework para abrir o relatório detalhado de conformidade.</li>
    </ol>

    <figure class="doc-figure">
        <img src="app/docs/grc-frameworks.png" alt="Compliance Frameworks page with per-framework compliance cards" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Frameworks.</strong> Os blocos superiores contam seus frameworks, prontidão média, total de requisitos e quantos <em>precisam de atenção</em>. Cada cartão mostra o rosca de conformidade de um framework, sua maturidade média e a distribuição Conforming / Partial / Non-Conforming / Total-Mapped. Clique em qualquer cartão para abrir o relatório completo de conformidade daquele framework.</figcaption>
    </figure>

    <h3>Árvore de Requisitos do Framework</h3>
    <p>Se você navegar para esta página <em>sem</em> selecionar uma avaliação (ou clicando em um link de framework de outro lugar), você verá a visualização de <strong>Requirement Tree</strong>. Isso mostra a estrutura hierárquica de todos os requisitos dentro de um framework, junto com controles mapeados e status de implementação. Administradores e usuários Cyber GRC podem adicionar, editar e excluir requisitos personalizados aqui.</p>
</div>


<div class="doc-section" id="grc-controls">
    <h2>Controles Internos</h2>
    <p><strong>Controles Internos</strong> são as medidas de segurança específicas que sua organização implementou. Exemplos: "Autenticação Multifator em todos os sistemas", "Backups criptografados diários", "Testes de penetração anuais".</p>

    <h3>Como Criar um Controle</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Internal Controls</span>.</li>
        <li>Clique em <span class="btn-label">+ New Control</span>.</li>
        <li>Preencha os campos:
            <ul>
                <li><span class="field-label">Control Title</span> &mdash; Um nome curto (ex.: "MFA para todas as contas de usuário")</li>
                <li><span class="field-label">Description</span> &mdash; Descrição detalhada do que este controle faz</li>
                <li><span class="field-label">Control Type</span> &mdash; Preventivo, Detectivo, Corretivo ou Diretivo</li>
                <li><span class="field-label">Category</span> &mdash; Técnico, Administrativo ou Físico</li>
                <li><span class="field-label">Implementation Status</span> &mdash; Planned, In Progress, Implemented ou Not Applicable</li>
                <li><span class="field-label">Effectiveness</span> &mdash; Not Tested, Ineffective, Partially Effective ou Effective</li>
                <li><span class="field-label">Risk Level</span> &mdash; Low, Medium, High ou Critical</li>
                <li><span class="field-label">Owner</span> &mdash; A pessoa responsável (limitado a membros dos grupos Administrator e Cyber GRC)</li>
                <li><span class="field-label">Test Frequency</span> &mdash; Com que frequência este controle é testado (Daily, Weekly, Monthly, etc.)</li>
            </ul>
        </li>
        <li>Em <strong>Framework Mapping</strong>, selecione quais requisitos de framework este controle satisfaz. Você pode mapear um único controle para requisitos em múltiplos frameworks.</li>
        <li>Clique em <span class="btn-label">Save</span>.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Benefício Principal &mdash; Mapeamento Entre Frameworks:</strong> Um único controle como "MFA" pode satisfazer requisitos em SOC 2 (CC6.1), ISO 27001 (A.8.5), PCI DSS (8.4.2) e NIST CSF (PR.AC-7) simultaneamente. Mapeie uma vez e cobre todos os frameworks.
    </div>
</div>


<div class="doc-section" id="grc-crosswalk">
    <h2>Crosswalk de Frameworks</h2>
    <p>O <strong>Framework Crosswalk</strong> mostra como a conformidade com um framework fornece automaticamente cobertura para outro. Por exemplo, se você é compatível com SOC 2, quanto da ISO 27001 você já cobre?</p>

    <h3>Como Usar</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Framework Crosswalk</span>.</li>
        <li>Selecione um <span class="field-label">Source Framework</span> (o framework que você já concluiu, ex.: "SOC 2").</li>
        <li>Selecione um <span class="field-label">Target Framework</span> (o framework com o qual deseja comparar, ex.: "ISO 27001").</li>
        <li>A tabela de crosswalk mostra quais requisitos-alvo são cobertos pelos seus controles de origem e quais têm lacunas.</li>
    </ol>
</div>


<div class="doc-section" id="grc-evidence">
    <h2>Biblioteca de Evidências</h2>
    <p>A <strong>Evidence Library</strong> é um repositório centralizado para todas as evidências de conformidade da sua organização.</p>

    <h3>Como Fazer Upload de Evidências</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Evidence Library</span>.</li>
        <li>Clique em <span class="btn-label">+ Upload Evidence</span>.</li>
        <li>Preencha: <span class="field-label">Title</span>, <span class="field-label">Evidence Type</span> (captura de tela, documento, certificado, configuração, relatório, etc.), <span class="field-label">Description</span> e opcionalmente uma <span class="field-label">Expiry Date</span>.</li>
        <li>Selecione o arquivo para upload.</li>
        <li>Clique em <span class="btn-label">Upload</span>. O arquivo é criptografado e armazenado com segurança.</li>
        <li>Você pode então vincular esta evidência a controles específicos ou respostas de avaliação.</li>
    </ol>

    <h3>Status de Evidências</h3>
    <table class="doc-table">
        <tr><th>Status</th><th>Significado</th></tr>
        <tr><td><strong>Current</strong></td><td>Evidência ativa e válida</td></tr>
        <tr><td><strong>Expired</strong></td><td>Após a data de expiração &mdash; precisa ser atualizada</td></tr>
        <tr><td><strong>Superseded</strong></td><td>Substituída por evidência mais recente</td></tr>
        <tr><td><strong>Draft</strong></td><td>Enviada, mas ainda não revisada ou finalizada</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-policies">
    <h2>Gestão de Políticas</h2>
    <p>A página de <strong>Policies</strong> fornece um ciclo de vida completo de políticas &mdash; desde a elaboração até a aprovação, publicação e revisão periódica.</p>

    <h3>Como Criar uma Política</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Policy Management</span> &rarr; <span class="menu-label">Policies</span>.</li>
        <li>Clique em <span class="btn-label">+ New Policy</span>.</li>
        <li>Preencha: <span class="field-label">Title</span>, <span class="field-label">Category</span> (Security, Privacy, Compliance, Operational, HR, IT, etc.), <span class="field-label">Review Frequency</span> (com que frequência a política deve ser revisada).</li>
        <li>Escreva o conteúdo da política usando o editor de texto rico.</li>
        <li>Clique em <span class="btn-label">Save</span>. A política é criada com o status <span class="status-label">Draft</span>.</li>
        <li>Quando pronto, envie para <strong>Review</strong> &rarr; <strong>Approve</strong> &rarr; <strong>Publish</strong>.</li>
    </ol>

    <h3>Ciclo de Vida da Política</h3>
    <p><span class="status-label">Draft</span> &rarr; <span class="status-label">Review</span> &rarr; <span class="status-label">Approved</span> &rarr; <span class="status-label">Published</span> &rarr; (Revisão Periódica ou <span class="status-label">Retired</span>)</p>
</div>


<div class="doc-section" id="grc-audits">
    <h2>Auditorias &amp; Constatações</h2>
    <p>A página de <strong>Audits</strong> gerencia o ciclo de vida completo de auditoria &mdash; desde o planejamento até o trabalho de campo, constatações, remediação e encerramento.</p>

    <h3>Como Criar uma Auditoria</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Audits</span>.</li>
        <li>Clique em <span class="btn-label">+ New Audit</span>.</li>
        <li>Preencha: <span class="field-label">Title</span>, <span class="field-label">Audit Type</span> (Internal, External, Certification, Surveillance, Readiness), <span class="field-label">Framework</span>, <span class="field-label">Lead Auditor</span>, <span class="field-label">Planned Start/End Dates</span>.</li>
        <li>Clique em <span class="btn-label">Create</span>.</li>
    </ol>

    <h3>Registrando Constatações</h3>
    <ol class="steps">
        <li>Abra uma auditoria e clique em <span class="btn-label">+ Add Finding</span>.</li>
        <li>Preencha: <span class="field-label">Title</span>, <span class="field-label">Severity</span> (Informational, Low, Medium, High, Critical), <span class="field-label">Finding Type</span> (Nonconformity, Observation, Opportunity, Strength) e <span class="field-label">Description</span>.</li>
        <li>Mapeie a constatação para requisitos ou controles específicos do framework.</li>
        <li>Atribua remediação a um membro da equipe com uma data de prazo.</li>
        <li>Acompanhe o progresso da remediação até o status <strong>Verified Closed</strong>.</li>
    </ol>

    <h3>Status de Auditoria</h3>
    <table class="doc-table">
        <tr><th>Status</th><th>Significado</th></tr>
        <tr><td><strong>Planning</strong></td><td>Definindo escopo, objetivos e cronograma</td></tr>
        <tr><td><strong>Fieldwork</strong></td><td>Testes ativos, revisão de evidências e entrevistas</td></tr>
        <tr><td><strong>Reporting</strong></td><td>Elaboração do relatório de auditoria e documentação das constatações</td></tr>
        <tr><td><strong>Remediation</strong></td><td>As constatações foram relatadas; a equipe está corrigindo os problemas</td></tr>
        <tr><td><strong>Closed</strong></td><td>Todas as constatações resolvidas e auditoria concluída</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-risks">
    <h2>Registro de Riscos</h2>
    <p>O <strong>Risk Register</strong> rastreia os riscos organizacionais com pontuação de probabilidade/impacto, planos de tratamento e links para controles.</p>

    <h3>Como Adicionar um Risco</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Risk Register</span>.</li>
        <li>Clique em <span class="btn-label">+ New Risk</span>.</li>
        <li>Preencha: <span class="field-label">Title</span>, <span class="field-label">Description</span>, <span class="field-label">Category</span> (Strategic, Operational, Financial, Compliance, Reputational, Technology, Third Party).</li>
        <li>Defina a <span class="field-label">Likelihood</span> (Rare, Unlikely, Possible, Likely, Almost Certain) e o <span class="field-label">Impact</span> (Insignificant, Minor, Moderate, Major, Catastrophic).</li>
        <li>O sistema calcula o <strong>Inherent Risk Score</strong> (Probabilidade &times; Impacto, em uma escala de 1-25).</li>
        <li>Selecione uma <span class="field-label">Treatment Strategy</span>: Accept, Mitigate, Transfer ou Avoid.</li>
        <li>Vincule controles internos relevantes para mostrar como o risco está sendo mitigado. O sistema calcula o <strong>Residual Risk Score</strong> após os controles.</li>
    </ol>
</div>


<div class="doc-section" id="grc-monitors">
    <h2>Monitores Contínuos</h2>
    <p><strong>Continuous Monitors</strong> são verificações automatizadas que validam seus controles de segurança em um cronograma (horário, diário, semanal ou mensal).</p>

    <h3>Como Criar um Monitor</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Continuous Monitors</span>.</li>
        <li>Clique em <span class="btn-label">+ New Monitor</span>.</li>
        <li>Preencha: <span class="field-label">Title</span>, <span class="field-label">Check Type</span>, <span class="field-label">Frequency</span> (Hourly, Daily, Weekly, Monthly) e a <span class="field-label">Collector Configuration</span> (configurações JSON para a verificação).</li>
        <li>Vincule o monitor a um controle interno.</li>
        <li>Ative o monitor. Ele será executado automaticamente no cronograma configurado.</li>
        <li>Veja os resultados (Pass, Fail, Error, Warning) e o histórico de execução na página de detalhes do monitor.</li>
    </ol>
</div>


<div class="doc-section" id="grc-tasks">
    <h2>Caixa de Entrada de Tarefas</h2>
    <p>A <strong>Task Inbox</strong> mostra todas as tarefas de GRC atribuídas a você em todas as avaliações. As tarefas são criadas durante as avaliações para delegar trabalhos como coleta de evidências, remediação, revisões ou documentação.</p>

    <h3>Como Usar</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Task Inbox</span>.</li>
        <li>Você verá uma lista de tarefas atribuídas a você. Cada tarefa mostra: título, tipo (Evidence Request, Remediation, Review, Documentation, Implementation), prioridade, data de prazo e status.</li>
        <li>Clique em uma tarefa para ver os detalhes e atualizar seu status.</li>
        <li>Marque as tarefas como <span class="status-label">In Progress</span> quando começar a trabalhar, e <span class="status-label">Completed</span> quando concluir.</li>
    </ol>
</div>


<div class="doc-section" id="grc-dashboard">
    <h2>Painel GRC</h2>
    <p>O <strong>GRC Dashboard</strong> é o seu centro de comando de conformidade &mdash; uma visão geral em uma única página de toda a sua postura de GRC.</p>

    <h3>O Que o Painel Mostra</h3>
    <ul>
        <li><strong>Mapa de Calor de Conformidade por Framework</strong> &mdash; Percentuais de conformidade codificados por cores para cada framework</li>
        <li><strong>Progresso de Implementação de Controles</strong> &mdash; Quantos controles estão implementados vs. planejados</li>
        <li><strong>Atualidade das Evidências</strong> &mdash; Quantos itens de evidência estão atuais, prestes a expirar ou expirados</li>
        <li><strong>Constatações Abertas</strong> &mdash; Contagem e distribuição por gravidade das constatações de auditoria não resolvidas</li>
        <li><strong>Status de Revisão de Políticas</strong> &mdash; Políticas com revisão pendente</li>
        <li><strong>Saúde dos Monitores</strong> &mdash; Status de aprovação/reprovação dos monitores contínuos</li>
        <li><strong>Resumo do Registro de Riscos</strong> &mdash; Riscos abertos por gravidade</li>
    </ul>

    <h3>Como Acessar</h3>
    <p>Navegue até <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">GRC Dashboard</span>.</p>
</div>


<!-- ================================================================
     TPRM MODULE
     ================================================================ -->
<div class="doc-section" id="tprm-overview">
    <h2>Módulo TPRM: O Que é Gestão de Risco de Terceiros?</h2>
    <p>Toda empresa depende de fornecedores externos &mdash; provedores de nuvem, empresas de folha de pagamento, plataformas de marketing, consultores de TI. Cada fornecedor pode ter acesso aos seus dados ou sistemas. O <strong>TPRM</strong> ajuda você a responder: "Qual é o risco de cada fornecedor e eles estão protegendo nossos dados?"</p>
    <ul>
        <li>Adicione e rastreie todos os seus fornecedores em um só lugar</li>
        <li>Atribua um nível de risco (Tier 1 = maior risco, Tier 3 = menor)</li>
        <li>Envie questionários de segurança (avaliações) para fornecedores</li>
        <li>Pontue fornecedores automaticamente usando serviços externos de classificação de segurança</li>
        <li>Realize análise quantitativa de risco (FAIR) para estimar potenciais perdas financeiras</li>
        <li>Rastreie o risco de 4ª parte (os fornecedores dos seus fornecedores)</li>
        <li>Descubra aplicativos SaaS não gerenciados (Shadow SaaS)</li>
    </ul>
</div>

<div class="doc-section" id="tprm-add-vendor">
    <h2>Adicionando um Novo Fornecedor</h2>
    <ol class="steps">
        <li>Na barra lateral esquerda, expanda <span class="menu-label">TPRM Module</span> e depois expanda a seção <span class="menu-label">Stakeholders</span>.</li>
        <li>Clique em <span class="menu-label">New Request</span>. Isso abre o formulário de integração de fornecedor.</li>
        <li>Preencha os campos obrigatórios:
            <ul>
                <li><span class="field-label">Vendor Name</span> &mdash; O nome legal da empresa (ex.: "Acme Cloud Services")</li>
                <li><span class="field-label">Vendor Domain</span> &mdash; O domínio do site sem https:// (ex.: "acmecloud.com"). Usado pelos mecanismos de pontuação de segurança para escanear o fornecedor.</li>
            </ul>
        </li>
        <li>Preencha os campos opcionais recomendados:
            <ul>
                <li><span class="field-label">Vendor Type</span> &mdash; Technology, Professional Services, Financial Services, HR/Benefits, etc.</li>
                <li><span class="field-label">Vendor Tier</span> &mdash; 1 (Critical), 2 (Important) ou 3 (Standard)</li>
                <li><span class="field-label">Primary Contact Name</span>, <span class="field-label">Email</span>, <span class="field-label">Phone</span></li>
                <li><span class="field-label">PII Record Count</span> &mdash; Quantos registros pessoais este fornecedor acessa</li>
                <li><span class="field-label">SPII Record Count</span> &mdash; Quantos registros pessoais sensíveis (CPFs, dados de saúde)</li>
            </ul>
        </li>
        <li>Clique em <span class="btn-label">Save</span>. O fornecedor é criado com o status <strong>Draft</strong>.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Níveis de Fornecedor Explicados:</strong><br>
        &bull; <strong>Tier 1 (Critical)</strong> &mdash; Fornecedores com acesso a dados sensíveis ou sistemas críticos. Requerem avaliação completa.<br>
        &bull; <strong>Tier 2 (Important)</strong> &mdash; Fornecedores com acesso moderado. Requerem avaliação padrão.<br>
        &bull; <strong>Tier 3 (Standard)</strong> &mdash; Fornecedores de baixo risco. Podem requerer apenas uma revisão básica.
    </div>
</div>

<div class="doc-section" id="tprm-lifecycle">
    <h2>Ciclo de Vida do Fornecedor</h2>
    <p>Os fornecedores passam por um ciclo de vida definido:</p>
    <p><span class="status-label">Draft</span> &rarr; <span class="status-label">Pending Review</span> &rarr; <span class="status-label">In Review</span> &rarr; <span class="status-label">Approved</span> (ou <span class="status-label">Rejected</span>) &rarr; <span class="status-label">Active</span> &rarr; <span class="status-label">Annual Review</span> &rarr; <span class="status-label">Offboarded</span></p>
    <p>Cada etapa aciona fluxos de trabalho, notificações e ações necessárias apropriadas.</p>
</div>

<div class="doc-section" id="tprm-assessments">
    <h2>Avaliações de Fornecedores</h2>
    <p>As avaliações de fornecedores são questionários de segurança enviados aos fornecedores para avaliar sua postura de segurança. Navegue até <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Assessments</span> para gerenciá-las.</p>
    <ol class="steps">
        <li>Abra a página de detalhes de um fornecedor.</li>
        <li>Clique em <span class="btn-label">Send Assessment</span>.</li>
        <li>Selecione o modelo de avaliação adequado para o nível do fornecedor.</li>
        <li>O fornecedor recebe um e-mail com um link para preencher o questionário.</li>
        <li>Após o envio, revise as respostas do fornecedor e pontue-as.</li>
    </ol>
</div>

<div class="doc-section" id="assessment-forms">
    <h2>Formulários de Avaliação: Baixar, Preencher, Importar &amp; Preenchimento Automático por IA <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>Nem todo fornecedor quer responder a um questionário no navegador. Na página de uma avaliação individual (<span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Assessments</span> &rarr; abra uma avaliação) você pode entregar ao fornecedor uma cópia offline, receber de volta um arquivo concluído ou deixar um provedor de IA preencher previamente as respostas a partir dos próprios certificados do fornecedor. Os botões ficam em uma linha perto do topo da avaliação.</p>

    <h3>Baixe a avaliação como um arquivo preenchível</h3>
    <ul>
        <li><span class="btn-label">Download PDF</span> &mdash; um formulário PDF preenchível. Cada pergunta se torna um campo de formulário real, para que o fornecedor possa digitar e marcar caixas diretamente no arquivo.</li>
        <li><span class="btn-label">Download Excel</span> &mdash; uma planilha <code>.xlsx</code> real que pode ser preenchida no Excel, Google Sheets ou LibreOffice. Perguntas de escolha única recebem menus suspensos na célula, e perguntas condicionais ficam esmaecidas automaticamente quando não se aplicam.</li>
    </ul>
    <div class="callout callout-info">
        <strong>O PDF preenchível agora funciona em qualquer navegador, não apenas no Adobe.</strong> As caixas de seleção carregam aparências embutidas, portanto são exibidas e alternadas no Chrome, Edge e outros visualizadores de PDF integrados (anteriormente funcionavam apenas no Adobe Acrobat/Reader). Um campo de nome digitado rotulado <strong>&ldquo;SIGNATURE (TYPE FULL NAME)&rdquo;</strong> permite que qualquer pessoa assine em qualquer visualizador; os campos de assinatura digital e de data de assinatura exclusivos do Adobe permanecem ocultos exceto no Acrobat/Reader, que pode realmente usá-los.
    </div>

    <h3>Importe uma avaliação concluída (PDF, Excel ou CSV)</h3>
    <p>Quando o fornecedor devolver o arquivo finalizado, clique em <span class="btn-label">Import Completed Assessment</span> e faça o upload. A plataforma detecta o formato automaticamente &mdash; um <strong>PDF</strong>, <strong>Excel (.xlsx)</strong> ou <strong>CSV</strong> concluído &mdash; e mescla as respostas às respostas existentes da avaliação.</p>
    <div class="callout callout-warning">
        <strong>A Referência do arquivo deve corresponder.</strong> Cada arquivo baixado carrega uma <strong>Referência</strong> oculta (o ID da avaliação). Se a Referência estiver ausente ou pertencer a uma avaliação diferente, a importação é recusada e nada é gravado &mdash; portanto as respostas nunca podem cair na avaliação errada.
    </div>

    <h3>Tem um certificado em vez disso? <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Um fornecedor que preenche uma avaliação pode receber um atalho: se possuir uma certificação relevante, pode enviá-la em vez de responder a todas as perguntas. O prompt <strong>&ldquo;Do you have a Certificate?&rdquo;</strong> agora mostra as <strong>Certificate Upload Instructions</strong> que o autor do modelo escreveu, portanto não se limita mais à ISO 27001 &mdash; um modelo pode convidar um SOC 2 Type 2, ISO 27001 ou qualquer outro certificado. (Os autores de modelos definem esse texto no Template Builder; veja <a href="#admin-templates">Construtor de Modelos de Avaliação</a>.)</p>

    <h3>Preenchimento Automático por IA a partir de Certificações <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Se o seu administrador configurou um <a href="#admin-ai">provedor de IA</a>, um revisor autorizado pode deixar a IA ler os documentos de certificação enviados pelo fornecedor e preencher previamente o questionário. Clique em <span class="btn-label">&#9889; Auto-Fill from Certifications</span> na página da avaliação.</p>
    <figure class="doc-figure">
        <img src="app/docs/assessment-ai-autofill.png" alt="The Auto-Fill from Certifications button on a vendor assessment" loading="lazy">
        <figcaption><strong>Auto-Fill from Certifications.</strong> Com um provedor de IA configurado, o botão aparece ao lado de <strong>Download PDF</strong>, <strong>Download Excel</strong> e <strong>Import Completed Assessment</strong>. Ele lê os documentos de certificação atuais do fornecedor e preenche as perguntas que esses documentos respondem.</figcaption>
    </figure>
    <p>Ao clicar, você é lembrado: <em>&ldquo;This will analyze the vendor's certification documents and pre-fill unanswered questions. Existing answers will not be changed.&rdquo;</em> A IA então percorre os certificados do fornecedor e relata, por exemplo, <em>&ldquo;Filled 12 of 30 unanswered questions.&rdquo;</em> Algumas coisas a saber:</p>
    <ul>
        <li><strong>Apenas certificados atuais são usados.</strong> Ela lê os documentos enviados pelo fornecedor cujo tipo é <em>Certification</em> e que estão <strong>ativos e não expirados</strong> (documentos PDF, CSV e Excel; os mais recentes). Um certificado expirado ou substituído é ignorado.</li>
        <li><strong>Ela apenas preenche espaços em branco.</strong> As perguntas que você já respondeu são deixadas intactas, e ela nunca sobrescreve uma resposta existente.</li>
        <li><strong>Ela responde apenas a partir do que os documentos realmente dizem.</strong> A IA é instruída a não adivinhar; qualquer coisa que ela não possa sustentar com confiança a partir dos documentos é deixada sem resposta para uma pessoa concluir.</li>
        <li><strong>Você mantém o controle.</strong> As respostas preenchidas são salvas e a página recarrega mostrando-as, para que você possa revisar e alterar qualquer resposta antes que a avaliação seja enviada.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Quem pode usá-lo e quando aparece.</strong> O botão é exibido apenas para usuários <strong>Administrators</strong> e <strong>Cyber TPRM</strong>, somente quando um provedor de IA está habilitado, e somente quando o fornecedor tem pelo menos um documento de certificação atual em arquivo. Fica oculto em avaliações concluídas.
    </div>
</div>

<div class="doc-section" id="tprm-action-plan">
    <h2>Plano de Ação do Fornecedor <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>A aba <strong>Action Plan</strong> na página de um fornecedor permite que a equipe cibernética agende trabalho de acompanhamento para aquele fornecedor &mdash; contatar o fornecedor, enviar outra avaliação, forçar uma revisão anual &mdash; com uma data de prazo, responsáveis e um conjunto contínuo de notas de status. Um trabalho diário dispara cada ação quando sua data chega e a transforma em uma tarefa rastreada.</p>
    <p>Abra um fornecedor em <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Onboarding</span> &rarr; <span class="menu-label">My Vendors</span>, depois clique na aba <strong>Action Plan</strong>. A aba está disponível para usuários <strong>Administrators</strong> e <strong>Cyber TPRM</strong>.</p>

    <h3>Agendando uma ação</h3>
    <ol class="steps">
        <li>Na aba <strong>Action Plan</strong>, clique em <span class="btn-label">+ Create Action</span>.</li>
        <li>Escolha a <span class="field-label">Action</span>: <strong>Contact Vendor</strong>, <strong>Contact Stakeholder</strong>, <strong>Send Assessment</strong> ou <strong>Force Annual Review</strong>. (Se você escolher <strong>Send Assessment</strong>, um seletor de <span class="field-label">Vendor Assessment</span> aparece para que você escolha qual modelo enviar.)</li>
        <li>Defina a <span class="field-label">Due Date</span> &mdash; o dia em que a ação deve disparar.</li>
        <li>Em <span class="field-label">Assign to (Cyber TPRM)</span>, marque um ou mais responsáveis Cyber TPRM. (Se não houver nenhum, a ação recorre ao stakeholder do fornecedor.)</li>
        <li>Opcionalmente, marque <span class="field-label">Email assigned individuals when this action fires</span> e use <span class="field-label">Notification email addresses</span> para enviar a endereços específicos em vez disso &mdash; separados por vírgula. Deixe em branco para usar os e-mails das próprias contas dos responsáveis.</li>
        <li>Escreva uma <span class="field-label">Description</span> (ela é levada para a tarefa que é criada), depois clique em <span class="btn-label">Create Action</span>.</li>
    </ol>

    <h3>O que acontece quando uma ação dispara</h3>
    <p>Cada ação dispara uma vez, na sua data de prazo ou depois dela. Disparar cria uma <strong>Cyber To-Do</strong> vinculada que faz um link direto de volta para esta aba Action Plan, executa a ação (para <strong>Send Assessment</strong> ela envia o questionário por e-mail ao fornecedor; para <strong>Force Annual Review</strong> ela marca a revisão anual como devida) e &mdash; se você habilitou &mdash; envia e-mails aos responsáveis ou aos endereços que você listou.</p>

    <h3>Status de ação</h3>
    <p>Uma ação passa por estes status:</p>
    <p><span class="status-label">Pending</span> &rarr; <span class="status-label">In Progress</span> (definido automaticamente quando dispara) &rarr; <span class="status-label">Completed</span>, ou <span class="status-label">Problem</span> se algo deu errado quando disparou, ou <span class="status-label">Cancelled</span> se você a cancelar antes que dispare. Você pode alterar o status por conta própria a qualquer momento; o trabalho diário nunca sobrescreve um status que você definiu.</p>

    <h3>Notas de status</h3>
    <p>Abra uma ação para adicionar <strong>Status Notes</strong> com data conforme o trabalho avança. Digite uma nota e clique em <span class="btn-label">Add Note</span>. Você pode editar ou excluir suas próprias notas; administradores podem editar ou excluir as de qualquer pessoa. Cada criação, edição e exclusão é registrada em auditoria.</p>

    <div class="callout callout-info">
        <strong>O trabalho &ldquo;Vendor Remediation Schedule&rdquo;.</strong> O trabalho diário que dispara as ações devidas se chama <strong>Vendor Remediation Schedule</strong> e é executado todos os dias às <strong>7:00</strong> por padrão. Os administradores podem habilitá-lo, desabilitá-lo ou reprogramá-lo na página <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Scheduler</span>. Se ele estiver desligado, ele se atualiza na próxima vez que for executado, disparando tudo o que se tornou devido nesse meio-tempo.
    </div>
</div>

<div class="doc-section" id="tprm-srs">
    <h2>Scorecard de Risco de Segurança (SRS)</h2>
    <p>O SRS fornece uma pontuação de segurança externa automatizada para cada fornecedor com base na configuração DNS, SSL/TLS, segurança de e-mail (SPF, DKIM, DMARC), portas abertas e outros indicadores técnicos.</p>
    <p>Navegue até <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Security Risk Scorecard</span>.</p>
</div>

<div class="doc-section" id="tprm-fair">
    <h2>Análise FAIR</h2>
    <p>O <strong>FAIR</strong> (Factor Analysis of Information Risk) é um modelo quantitativo de risco que estima a perda financeira provável de um evento de segurança envolvendo um fornecedor.</p>
    <p>Navegue até <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">FAIR Analysis</span> para criar e visualizar análises.</p>
</div>

<div class="doc-section" id="tprm-fourth-party">
    <h2>Risco de 4ª Parte</h2>
    <p>Rastreie os fornecedores dos quais <em>seus fornecedores</em> dependem. Se seu provedor de nuvem usa um subcontratado para armazenamento de dados, isso é um risco de 4ª parte. Na barra lateral, você pode abrir <span class="menu-label">4th Party Risk</span> (concentração de tecnologia), <span class="menu-label">CVE Search</span> e <span class="menu-label">Subprocessors</span>. Está disponível para administradores e usuários Cyber TPRM; auditores podem visualizar, mas não agir.</p>

    <h3>Concentração de subprocessadores <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Abra <span class="menu-label">Subprocessors</span> para ver a visualização de <strong>Subprocessor Concentration</strong>: cada subprocessador que seus fornecedores declararam e quantos dos seus fornecedores usam cada um. Um subprocessador compartilhado por vários fornecedores é destacado &mdash; essa dependência compartilhada é um risco de concentração da cadeia de suprimentos. (Os subprocessadores são adicionados a um fornecedor a partir da página de detalhes desse fornecedor.)</p>

    <h3>Envie uma avaliação a todos que usam um subprocessador <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Quando um subprocessador concentra risco, você pode pesquisar os fornecedores que dependem dele em uma única ação:</p>
    <ol class="steps">
        <li>Na lista de Subprocessors, clique em <span class="btn-label">Send Assessment</span> na linha daquele subprocessador.</li>
        <li>No seletor de fornecedores, escolha quais dos fornecedores que usam aquele subprocessador devem receber a avaliação (ou <span class="field-label">Select All Visible</span>), depois continue.</li>
        <li>Escolha um <span class="field-label">Assessment Template</span> e uma janela de <span class="field-label">Expires In</span> (14, 30, 60 ou 90 dias), depois clique em <span class="btn-label">Assign Assessment</span>.</li>
    </ol>
    <p>Cada fornecedor selecionado recebe o questionário por e-mail (uma solicitação de informações), e um lembrete é rastreado para que os acompanhamentos saiam automaticamente. Fornecedores sem e-mail em arquivo são ignorados, e qualquer envio que falhe é tentado novamente pelo trabalho de lembrete. O mesmo fluxo <strong>Assign Assessment</strong> está disponível nas visualizações de concentração de tecnologia e de CVE.</p>
</div>

<div class="doc-section" id="tprm-shadow-saas">
    <h2>Descoberta de Shadow SaaS</h2>
    <p>Descubra aplicativos SaaS sendo usados em toda a sua organização que podem não ter sido formalmente aprovados ou avaliados. Navegue até <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Shadow SaaS</span>. Na v2.6.2, esta lista pode ser preenchida automaticamente pela integração de Shadow SaaS <a href="#shadow-saas-grip">Grip</a> ou <a href="#shadow-saas-hero">Hero</a>, e aplicativos não autorizados podem ser bloqueados no <a href="#zscaler">Zscaler</a>.</p>
</div>


<!-- ================================================================
     VENDOR ONBOARDING & PROCUREMENT (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="onboarding-workflow">
    <h2>Integração de Fornecedor &amp; Integração de Compras <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>Uma <strong>solicitação de integração de fornecedor</strong> é a forma como um novo fornecedor entra na plataforma. Ela passa por uma série de <strong>status</strong> desde o primeiro rascunho até a decisão final. Antes de a equipe cibernética revisar um fornecedor, ele deve primeiro ser <strong>integrado pelo seu processo de compras</strong> e ter um <strong>ID de Fornecedor (VID)</strong> válido. Esta seção explica o porquê e exatamente como funciona.</p>

    <h3>A jornada de integração (status)</h3>
    <table class="doc-table">
        <tr><th>Status</th><th>O Que Significa</th></tr>
        <tr><td><span class="status-label">Draft</span></td><td>A solicitação está sendo preenchida. Ainda não foi enviada para revisão.</td></tr>
        <tr><td><span class="status-label">Submitted</span></td><td>A solicitação passou nas verificações de envio e foi enviada para a equipe cibernética.</td></tr>
        <tr><td><span class="status-label">In Review</span></td><td>A equipe cibernética está revisando o fornecedor.</td></tr>
        <tr><td><span class="status-label">AI Review</span></td><td>Os serviços do fornecedor usam IA e está na etapa de revisão dedicada de IA (veja <a href="#ai-review">AI Review</a>).</td></tr>
        <tr><td><span class="status-label">Evaluation</span></td><td>O fornecedor está sendo testado ou avaliado.</td></tr>
        <tr><td><span class="status-label">Approved</span></td><td>O fornecedor foi aprovado e está integrado.</td></tr>
        <tr><td><span class="status-label">Rejected</span></td><td>O fornecedor não foi aprovado.</td></tr>
        <tr><td><span class="status-label">Inactive</span></td><td>O fornecedor não está mais ativo.</td></tr>
    </table>

    <h3>Encontrando suas solicitações de fornecedor</h3>
    <p>Vá para <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Stakeholders</span> &rarr; <span class="menu-label">Vendor Onboarding</span>. Você verá uma lista pesquisável de fornecedores com seu status, nível, pontuação de segurança (SRS) e ações rápidas (View, Edit). Use as pílulas de filtro no topo (por exemplo <strong>All</strong>, <strong>Approved</strong>, <strong>Review</strong>) para refinar a lista. Use <span class="btn-label">+ New Request</span> para iniciar um novo fornecedor.</p>
    <figure class="doc-figure">
        <img src="app/docs/vendor-onboarding-list.png" alt="Vendor Onboarding Requests list" loading="lazy">
        <figcaption><strong>Lista de Integração de Fornecedores.</strong> Pesquisa, pílulas de filtro e ações por fornecedor. A pílula de filtro <strong>Review</strong> é uma única visualização que combina fornecedores <em>In Review</em> e <em>AI Review</em>.</figcaption>
    </figure>

    <h3 id="procurement-onboarding">As duas coisas que todo fornecedor precisa antes da revisão</h3>
    <p>Abra um fornecedor e veja o cartão de <strong>Vendor Information</strong>. Dois campos controlam se o fornecedor pode ser enviado para revisão cibernética:</p>
    <ul>
        <li><strong>Procurement Onboarding</strong> &mdash; um campo Sim/Não respondendo à pergunta <em>"Este fornecedor concluiu a Integração de Compras?"</em> Deve ser definido como <strong>Yes</strong>.</li>
        <li><strong>Vendor ID (VID)</strong> &mdash; o identificador de 4&ndash;8 dígitos atribuído ao fornecedor pelo seu sistema de compras. Deve ser um número válido de 4&ndash;8 dígitos.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/vendor-info-card.png" alt="Vendor Information card showing Procurement Onboarding and Vendor ID fields" loading="lazy">
        <figcaption><strong>Cartão de Informações do Fornecedor.</strong> O <strong>Vendor ID (VID)</strong> e o campo de integração de compras precisam ser preenchidos antes que o fornecedor possa ser enviado. <em>Nota:</em> em instâncias atualizadas de uma versão anterior, este campo pode ainda aparecer como <strong>"VSU Onboarded"</strong>; na v2.6.2 está rotulado como <strong>"Procurement Onboarding"</strong> &mdash; é o mesmo campo.</figcaption>
    </figure>

    <h3>Enviando um fornecedor para revisão</h3>
    <ol class="steps">
        <li>Abra o fornecedor na lista <span class="menu-label">Vendor Onboarding</span> (a solicitação deve estar em <strong>Draft</strong>).</li>
        <li>No cartão <strong>Vendor Information</strong>, defina <span class="field-label">Procurement Onboarding</span> como <strong>Yes</strong> e insira um <span class="field-label">Vendor ID (VID)</span> válido (4&ndash;8 dígitos). Salve suas alterações.</li>
        <li>Clique em <span class="btn-label">Submit for Review</span>. Você será solicitado a confirmar: <em>"Submit this vendor for review? The vendor must have a valid VID and be onboarded at VSU."</em></li>
        <li>Se ambas as verificações passarem, o status muda para <strong>Submitted</strong> e a equipe cibernética é notificada.</li>
    </ol>
    <div class="callout callout-danger">
        <strong>Se o envio for bloqueado,</strong> você verá uma destas mensagens:
        <ul style="margin:8px 0 0;">
            <li>"Cannot submit: Vendor must be onboarded at VSU before submission. Please complete the onboarding assessment with VSU details." &rarr; defina <strong>Procurement Onboarding</strong> como <strong>Yes</strong>.</li>
            <li>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits). Please complete the onboarding assessment with the VSU Vendor ID." &rarr; insira um <strong>Vendor ID</strong> válido de 4&ndash;8 dígitos.</li>
        </ul>
        Veja <a href="#troubleshooting">Solução de Problemas</a> para saber por que esta regra existe.
    </div>
</div>

<div class="doc-section" id="custom-onboarding">
    <h2>Campos de Integração Personalizados &amp; a Aba Custom Data <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>Os campos padrão de fornecedor (nome, domínio, nível, VAT e assim por diante) cobrem a maioria das necessidades, mas toda organização rastreia algo extra. Na v2.6.2, um modelo de integração pode definir <strong>campos personalizados</strong> que não têm uma coluna padrão de fornecedor. Seus valores são capturados por fornecedor e exibidos na aba <strong>Custom Data</strong> do fornecedor.</p>

    <h3>Onde os valores personalizados ficam: a aba Custom Data</h3>
    <p>Abra um fornecedor (<span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Onboarding</span> &rarr; <span class="menu-label">My Vendors</span> &rarr; abra um fornecedor). Se o modelo de integração do fornecedor definir algum campo personalizado, uma aba <strong>Custom Data</strong> aparece ao lado das outras abas do fornecedor, com uma contagem de quantos valores personalizados estão em arquivo. A aba é somente leitura até você clicar em <span class="btn-label">Edit</span>; faça suas alterações e clique em <span class="btn-label">Save Custom Data</span>. Os campos são agrupados por sua seção de modelo. Usuários que têm permissão para ver um campo, mas não editá-lo, o veem marcado como <em>(view only)</em>.</p>

    <h3>Definindo um campo personalizado (administradores)</h3>
    <p>Um campo personalizado é apenas uma pergunta em um modelo da categoria <strong>Onboarding</strong> cujo <span class="field-label">Field Name</span> é um que <em>não</em> é uma coluna de fornecedor integrada. Há duas etapas, ambas no Portal Admin:</p>
    <ol class="steps">
        <li><strong>Registre o nome do campo.</strong> Vá para <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Field Reference</span>, clique em <span class="btn-label">+ Add Field</span> e adicione seu campo personalizado (letras minúsculas, números e sublinhados; ex.: <code>data_residency_region</code>). Escolha um tipo de coluna (texto, número, data, etc.) e a categoria <span class="field-label">Onboarding</span>.</li>
        <li><strong>Adicione uma pergunta que mapeie para ele.</strong> Em <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Template Builder</span>, abra seu modelo de integração, adicione uma pergunta e defina seu <span class="field-label">Field Name</span> para o campo que você acabou de registrar. Veja <a href="#admin-templates">Construtor de Modelos de Avaliação</a>.</li>
    </ol>

    <h3>Novos tipos de campo para respostas mais ricas <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Além dos tipos existentes de texto, número, data, menu suspenso e rádio, as perguntas (personalizadas ou padrão) agora podem usar:</p>
    <table class="doc-table">
        <tr><th>Tipo</th><th>O que o fornecedor vê</th></tr>
        <tr><td><strong>Checkboxes</strong></td><td>Uma lista de seleção múltipla &mdash; marque cada opção que se aplica.</td></tr>
        <tr><td><strong>Button Group (Multi)</strong></td><td>A mesma seleção múltipla, exibida como uma linha de botões de alternância.</td></tr>
        <tr><td><strong>Phone</strong></td><td>Um número de telefone com um seletor de código de país &amp; bandeira (veja <a href="#question-types">Tipos de Pergunta Telefone &amp; VAT</a>).</td></tr>
        <tr><td><strong>VAT Number</strong></td><td>Um número de IVA da UE com dupla entrada e validação VIES ao vivo (veja <a href="#question-types">Tipos de Pergunta Telefone &amp; VAT</a>).</td></tr>
    </table>
    <p>Os equivalentes de seleção única (<strong>Dropdown</strong>, <strong>Radio Buttons</strong>, <strong>Button Group</strong>) continuam disponíveis. Os tipos de seleção múltipla precisam de uma lista de <span class="field-label">Options</span> (uma por linha).</p>

    <h3>Controlando quem pode ver e editar um campo (controle por função) <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Em modelos de integração, cada seção e pergunta <strong>personalizada</strong> carrega dois controles de função, para que você possa manter campos sensíveis longe de pessoas que não deveriam vê-los:</p>
    <ul>
        <li><span class="field-label">Visible to Roles</span> &mdash; quais funções podem <em>ver</em> o campo.</li>
        <li><span class="field-label">Visible and Editable Roles</span> &mdash; quais funções podem <em>editá-lo</em>.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Os campos personalizados são privados por padrão.</strong> Ao contrário de uma pergunta padrão, um campo personalizado fica oculto até que você conceda uma função. Até que uma função seja concedida, apenas superadministradores podem vê-lo ou editá-lo. Um campo que um visualizador não tem permissão para ver é deixado de fora da aba Custom Data, da página do fornecedor, da exportação CSV e da API para aquela pessoa. (Esse padrão de somente-concessão se aplica a campos personalizados; as perguntas padrão de integração nunca são controladas dessa forma.)
    </div>
    <p>Tanto a concessão de uma seção quanto a concessão de uma pergunta devem permitir uma pessoa antes que ela veja aquela pergunta, para que você possa ocultar uma seção inteira ou apenas campos individuais dentro dela.</p>

    <h3>Valores personalizados em exportações e na API</h3>
    <ul>
        <li><strong>Exportação CSV.</strong> Na lista <span class="menu-label">Vendor Onboarding</span>, <span class="btn-label">Export CSV</span> (administradores e Cyber TPRM) agora adiciona uma coluna por campo personalizado, nomeada <code>custom:&lt;field_name&gt;</code>, ao lado das colunas padrão.</li>
        <li><strong>REST API.</strong> A resposta de fornecedor único (<code>GET /vendors/{id}</code>) inclui um array <code>custom_onboarding_data</code>; cada entrada tem <code>field_name</code>, <code>label</code>, <code>value</code>, <code>type</code>, <code>section</code> e <code>template_name</code>.</li>
    </ul>
    <p>Ambos leem do mesmo lugar que a aba Custom Data e respeitam a mesma visibilidade por função &mdash; um campo que o solicitante (ou o dono da chave de API) não pode ver é apagado ou omitido.</p>
</div>

<div class="doc-section" id="ai-review">
    <h2>Revisão de IA para Fornecedores <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>Alguns fornecedores oferecem serviços que usam inteligência artificial. Esses fornecedores podem carregar riscos diferentes, por isso a v2.6.2 adiciona um status dedicado de <strong>AI Review</strong> para rastreá-los separadamente durante o processo de revisão.</p>

    <h3>Como um fornecedor entra em AI Review</h3>
    <p>No cartão <strong>Vendor Information</strong> há um campo <span class="field-label">Services Use AI</span>. Quando este está definido como <strong>Yes</strong>, um revisor autorizado (um usuário <strong>Cyber TPRM</strong> ou <strong>Administrator</strong>, enquanto edita o fornecedor) vê um link <span class="btn-label">Force AI Review</span> diretamente abaixo daquele campo.</p>
    <ol class="steps">
        <li>Abra o fornecedor e confirme que <span class="field-label">Services Use AI</span> está definido como <strong>Yes</strong>.</li>
        <li>Clique em <span class="btn-label">Force AI Review</span>. Confirme o prompt: <em>"Force this vendor into AI Review?"</em></li>
        <li>O status do fornecedor muda para <strong>AI Review</strong>.</li>
    </ol>
    <div class="callout callout-info">
        <strong>Por que o link pode não aparecer?</strong> O link <strong>Force AI Review</strong> só aparece quando (1) você tem permissão para aprovar, (2) está no modo de edição, (3) <strong>Services Use AI</strong> é <strong>Yes</strong> e (4) o fornecedor ainda não está em AI Review. Se <strong>Services Use AI</strong> for "No", você verá a mensagem <em>"AI Review can only be forced for vendors whose services use AI."</em></p>
    </div>
    <p>Na página de <a href="#procurement-cyber-status">Procurement Cyber Status</a> e no filtro <strong>Review</strong> da lista de fornecedores, fornecedores em <strong>In Review</strong> e <strong>AI Review</strong> são exibidos juntos &mdash; portanto nada em revisão fica oculto apenas porque está sendo revisado com IA.</p>
</div>

<div class="doc-section" id="procurement-cyber-status">
    <h2>Status Cibernético de Compras <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>A página de <strong>Cyber Status</strong> dá à <strong>equipe de compras</strong> uma visão simples e sempre atualizada de quais fornecedores a equipe cibernética está revisando e qual é a última informação sobre cada um &mdash; sem precisar de acesso às ferramentas de segurança completas. A equipe cibernética publica atualizações curtas com data; compras as lê aqui (e em um e-mail semanal).</p>
    <p>Abra em <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Procurement</span> &rarr; <span class="menu-label">Cyber Status</span>. Disponível para usuários <strong>Procurement</strong>, <strong>Cyber TPRM</strong> e <strong>Administrator</strong>.</p>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status.png" alt="Procurement Cyber Status page" loading="lazy">
        <figcaption><strong>Procurement &rarr; Cyber Status.</strong> Lista todos os fornecedores com status <em>In Review</em> ou <em>AI Review</em>, com o número de atualizações e a data da atualização mais recente. Quando nenhum fornecedor está em revisão, a tabela é substituída por uma mensagem "No vendors in review".</figcaption>
    </figure>

    <h3>Lendo o histórico de atualizações de um fornecedor</h3>
    <ol class="steps">
        <li>Clique no nome de um fornecedor na tabela <strong>Vendors in Review</strong>.</li>
        <li>O painel de <strong>Procurement Update History</strong> abre, mostrando cada atualização da mais recente para a mais antiga: data e hora, quem a escreveu, o status do fornecedor naquele momento e a nota em si.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status-updates.png" alt="Procurement Update History for a vendor in review" loading="lazy">
        <figcaption><strong>Histórico de atualizações de um fornecedor.</strong> Clicar no nome de um fornecedor abre seu <strong>Procurement Update History</strong>. Cada entrada mostra a data e hora, o autor, um distintivo para o status do fornecedor quando a nota foi escrita e a nota da equipe cibernética &mdash; para que compras possa ver exatamente onde cada revisão está. Históricos longos são paginados com o controle <em>Show&nbsp;per&nbsp;page</em>.</figcaption>
    </figure>

    <h3>Para revisores cibernéticos: publicando uma atualização para compras</h3>
    <p>Usuários Cyber TPRM e admins podem publicar uma atualização para um ou mais fornecedores de uma só vez:</p>
    <ol class="steps">
        <li>Na página <strong>Cyber Status</strong>, marque a caixa de seleção ao lado de cada fornecedor que deseja atualizar.</li>
        <li>Clique em <span class="btn-label">Provide Procurement with Update</span>.</li>
        <li>Na janela <strong>Provide Procurement with Update</strong>, digite sua nota na caixa <span class="field-label">Update</span>.</li>
        <li>Opcionalmente, use <span class="field-label">Change status</span> para avançar o(s) fornecedor(es) (por exemplo para <strong>Evaluation</strong>, <strong>Approved</strong> ou <strong>Rejected</strong>). Deixe em <em>Keep current status</em> para apenas adicionar uma nota.</li>
        <li>Clique em <span class="btn-label">Save Update</span>. A atualização é registrada para cada fornecedor selecionado.</li>
    </ol>

    <h3>O e-mail semanal de resumo para compras</h3>
    <p>Para manter compras informada sem que ninguém precise fazer login, a plataforma pode enviar por e-mail um <strong>resumo semanal</strong> listando todos os fornecedores em revisão junto com sua atualização mais recente. Por padrão, isso é enviado <strong>toda segunda-feira às 7:00</strong>.</p>
    <ol class="steps">
        <li>Um administrador vai a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email Settings</span> e encontra as opções de <strong>Procurement Update Digest</strong>.</li>
        <li>Ative o resumo e insira um ou mais endereços de e-mail de destinatários (separados por vírgulas).</li>
        <li>Salve. Você também pode enviar um imediatamente com <span class="btn-label">Send digest now</span> para testá-lo.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-scheduler.png" alt="Admin Scheduler showing the Procurement Update Digest job" loading="lazy">
        <figcaption><strong>Admin &rarr; Scheduler.</strong> O trabalho <strong>Procurement Update Digest</strong> (no final da lista) é executado semanalmente. O Scheduler é onde os admins habilitam, desabilitam e programam todos os trabalhos automatizados.</figcaption>
    </figure>
</div>

<!-- ================================================================
     INTEGRATIONS: GRIP + ZSCALER (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="shadow-saas-grip">
    <h2>Integração Grip Shadow SaaS <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>"Shadow SaaS" significa aplicativos em nuvem que os funcionários usam sem aprovação formal. A <strong>Grip Security</strong> é um serviço que descobre esses aplicativos. Na v2.6.2, você pode conectar sua conta Grip para que a plataforma importe automaticamente os aplicativos que o Grip encontra &mdash; junto com quantas pessoas usam cada um, uma pontuação de risco e alertas de segurança &mdash; e os liste na sua página de <a href="#tprm-shadow-saas">Shadow SaaS</a>.</p>
    <p>É configurado por um administrador em <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> na aba <strong>Grip</strong>. Grip é um dos dois provedores de Shadow SaaS (o outro é o <a href="#shadow-saas-hero">Hero</a>); apenas um pode ser habilitado por vez.</p>

    <h3>Conectando o Grip (passo a passo)</h3>
    <ol class="steps">
        <li>No Grip, crie um <strong>token de API</strong> e anote a URL base do seu locatário (termina em <code>/public/saas</code>, por exemplo <code>https://tenant.dep.grip.security/public/saas</code>).</li>
        <li>Na plataforma, vá para <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> e encontre o cartão <strong>Grip Security Connection</strong>.</li>
        <li>Marque <span class="field-label">Enable Grip Security integration</span>.</li>
        <li>Cole a URL do seu locatário em <span class="field-label">Server (Tenant Base URL)</span> e seu token em <span class="field-label">API Token</span>.</li>
        <li>Clique em <span class="btn-label">Save Configuration</span> e depois em <span class="btn-label">Test Connection</span> para confirmar. Uma mensagem de sucesso parece com <em>"Connected to Grip — sample returned 1 record(s)"</em>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grip-card.png" alt="Grip Security Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Grip Security Connection.</strong> Insira a URL do seu locatário e o token de API, salve e teste.</figcaption>
    </figure>

    <h3>Mantendo atualizado automaticamente</h3>
    <p>Use o cartão compartilhado de <strong>Scheduled Rehydration</strong> (abaixo das abas de provedores) para atualizar os dados do provedor habilitado em um cronograma. Marque <span class="field-label">Enable scheduled rehydration</span> e insira um <span class="field-label">Schedule (cron expression)</span> &mdash; por exemplo <code>0 2 * * *</code> para diariamente às 2&nbsp;AM; o cartão mostra um resumo em linguagem simples do que você digitou. O trabalho é instalado automaticamente no agendador do sistema (sem etapas manuais no servidor) e sobrevive a reinicializações. Você também pode clicar em <span class="btn-label">Run Now</span> para atualizar imediatamente. O mesmo cronograma serve qualquer provedor (Grip ou Hero) que esteja habilitado no momento.</p>

    <h3>O que você verá depois</h3>
    <p>Os aplicativos descobertos aparecem na página <span class="menu-label">Shadow SaaS</span> como entradas <strong>Pending</strong> com uma pontuação de risco (exibida em uma escala de 1&ndash;5), categoria e número de usuários. A partir daí você pode <strong>Allow</strong> um aplicativo (o que inicia sua integração como fornecedor), <strong>Deny</strong> (marcá-lo como não autorizado e opcionalmente bloqueá-lo no Zscaler) ou <strong>Dismiss</strong> (dispensá-lo).</p>
    <figure class="doc-figure">
        <img src="app/docs/shadow-saas.png" alt="Shadow SaaS list page" loading="lazy">
        <figcaption><strong>A página Shadow SaaS.</strong> Aplicativos descobertos e importados com seus riscos e ações. Aplicativos integrados como fornecedores são ignorados em sincronizações futuras, e qualquer um que você dispensar permanece dispensado.</figcaption>
    </figure>

    <h3>Fonte de dados Live vs. Local (em cache) <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>No cartão Grip Security Connection, <span class="field-label">Data source</span> controla de onde as páginas do Grip leem:</p>
    <ul>
        <li><strong>Live</strong> &mdash; chama a API do Grip para cada página. Sempre atual, mas mais pesado sobre a API.</li>
        <li><strong>Local (Hydrated/cached)</strong> &mdash; serve a partir da cópia dos dados do Grip mantida no banco de dados da plataforma. Mais leve sobre a API. No modo Local, cada sincronização <strong>atualiza totalmente</strong> essa cópia; entre sincronizações, as páginas servem a partir do instantâneo em vez de chamar o Grip.</li>
    </ul>

    <h3>Acompanhando e controlando uma sincronização <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Enquanto uma sincronização está em execução, o cartão <strong>Last Sync</strong> mostra uma leitura de progresso ao vivo &mdash; <em>&ldquo;Hydrating per-app rosters &mdash; NN% (D / T apps)&rdquo;</em> &mdash; acima de um botão <span class="btn-label">Stop Sync</span> que cancela a execução de forma cooperativa. Para apagar totalmente os dados do Grip servidos localmente, use <span class="btn-label">Truncate Data</span> no mesmo cartão: ele limpa as tabelas espelho do Grip, as linhas do Grip na lista de Shadow SaaS e a telemetria do Grip gravada nos registros de fornecedores (a aba SaaS Data). Seu histórico de sincronização é mantido, e a próxima sincronização re-hidrata tudo a partir do Grip.</p>

    <h3>Classificação SecurityScorecard (SSC) <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Quando o Grip está conectado, uma coluna <strong>SSC</strong> mostra a nota em letra do <strong>SecurityScorecard</strong> (A&ndash;F) de cada aplicativo ou fornecedor na lista de Shadow SaaS e na lista SRS de fornecedores, e na aba SaaS Data do fornecedor. Ela aparece apenas enquanto o Grip está habilitado.</p>

    <h3>A aba &ldquo;SaaS Data&rdquo; do fornecedor <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>Quando um fornecedor corresponde a um aplicativo descoberto pelo Grip, uma aba <strong>SaaS Data</strong> somente leitura aparece na página desse fornecedor, exibindo a telemetria do Grip coletada durante a sincronização sem sair do fornecedor: <strong>First Discovered</strong>, <strong>Active Accounts</strong> (um link para a lista de usuários afetados), <strong>Last Known Usage</strong>, classificação do aplicativo, a nota <strong>Security Scorecard</strong>, categoria, profundidade de IA, sinais de conformidade e suporte a SAML/MFA.</p>

    <h3>Alertas de violação do Grip <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>O Grip também pode alimentar incidentes de segurança na plataforma. Marque <span class="field-label">Flow Grip breach information into Breach / Cyber Alerts</span> no cartão de conexão e os alertas &ldquo;Security Incident Detected&rdquo; do Grip são gravados na sua lista de <a href="#breach-alerts">Alertas de Violação / Cibernéticos</a> a cada sincronização. (Isso também requer que o recurso de Alertas de Violação / Cibernéticos esteja habilitado em <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email Settings</span>.)</p>
    <div class="callout callout-info">
        <strong>Os dados pessoais são criptografados em repouso.</strong> Nomes, endereços de e-mail e outros detalhes pessoais nos dados do Grip em cache são criptografados no banco de dados e descriptografados apenas quando exibidos no aplicativo ou retornados pela API. Isso é automático e não requer configuração.
    </div>
</div>

<div class="doc-section" id="shadow-saas-hero">
    <h2>Integração Hero Shadow SaaS <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>A <strong>HERO Security</strong> é um provedor alternativo de Shadow SaaS. Em vez do Grip, você pode conectar uma conta HERO e a plataforma puxa os fornecedores que o HERO descobre &mdash; com seu status, uma pontuação de risco, o contato mais ativo e uma contagem de usuários &mdash; para a mesma lista de <a href="#tprm-shadow-saas">Shadow SaaS</a>. Grip e Hero são <strong>mutuamente exclusivos</strong>: habilitar o Hero desabilita automaticamente o Grip (e vice-versa), portanto a lista é sempre alimentada por exatamente um provedor.</p>
    <p>É configurado por um administrador em <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> na aba <strong>Hero</strong>.</p>

    <h3>Conectando o Hero (passo a passo)</h3>
    <ol class="steps">
        <li>No painel de administração do HERO, crie um <strong>cliente de API</strong> e copie seu <strong>Client ID</strong> e <strong>Client Secret</strong> (o segredo é exibido apenas uma vez).</li>
        <li>Na plataforma, vá para <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> e abra a aba <strong>Hero</strong> para encontrar o cartão <strong>HERO Security Connection</strong>.</li>
        <li>Marque <span class="field-label">Enable HERO Security integration</span> (isso desativa o Grip).</li>
        <li>Deixe <span class="field-label">Server (Base URL)</span> como <code>https://api.herosecurity.ai/stable</code> salvo indicação em contrário, e cole seu <span class="field-label">Client ID</span> e <span class="field-label">Client Secret</span>.</li>
        <li>Clique em <span class="btn-label">Save Configuration</span> e depois em <span class="btn-label">Test Connection</span>. Uma mensagem de sucesso parece com <em>"Connected to HERO — sample returned 1 record(s)"</em>.</li>
    </ol>

    <h3>O que você verá depois</h3>
    <p>Os fornecedores do HERO aparecem na página <span class="menu-label">Shadow SaaS</span> da mesma forma que os aplicativos do Grip &mdash; como entradas <strong>Pending</strong> que você pode Allow, Deny ou Dismiss. Para cada fornecedor a plataforma registra:</p>
    <ul>
        <li><strong>Pontuação de Risco (1&ndash;5)</strong> &mdash; derivada do problema de segurança aberto mais grave que o HERO tem para aquele fornecedor (critical&nbsp;=&nbsp;5 a low&nbsp;=&nbsp;2; fornecedores sem problemas abertos ficam sem pontuação). Esta é a mesma escala de 1&ndash;5 que o Grip usa.</li>
        <li><strong>Relationship Manager</strong> &mdash; o contato observado mais ativo do fornecedor (o usuário com maior atividade de e-mail).</li>
        <li><strong>Número de Usuários</strong> &mdash; quantos usuários foram observados interagindo com o fornecedor.</li>
        <li><strong>Tipo de Risco</strong> &mdash; um resumo dos sinais de engajamento do HERO (autorização, atividade, engajamento comercial) e a contagem de problemas abertos.</li>
    </ul>
    <p>Algumas colunas fornecidas por outras fontes (categoria do aplicativo, suporte a MFA, histórico de violações, volumes de tráfego, compartilhamento de arquivos) não fazem parte da API do HERO, portanto permanecem em branco para linhas Hero.</p>

    <div class="callout callout-info">
        <strong>Atenção ao tempo de sincronização.</strong> O HERO retorna seus dados por fornecedor e limita a taxa de requisições, portanto uma atualização completa de um locatário grande pode levar vários minutos em segundo plano. O trabalho agendado e o "Run Now" ambos se ajustam automaticamente para permanecer dentro dos limites do HERO.
    </div>
</div>

<div class="doc-section" id="zscaler">
    <h2>Integração de Bloqueio Zscaler <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>O <strong>Zscaler</strong> é um serviço de segurança web que pode bloquear o acesso a sites. Com esta integração, quando você <strong>Deny</strong> um aplicativo não autorizado na página Shadow SaaS, a plataforma pode adicionar automaticamente o domínio web desse aplicativo a uma lista de bloqueio na sua conta Zscaler &mdash; para que as pessoas não possam mais acessá-lo. Clicar em <strong>Allow</strong> posteriormente remove o bloqueio.</p>
    <p>É configurado por um administrador em <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span>, no cartão <strong>Zscaler Connection</strong>.</p>

    <h3>Conectando o Zscaler (passo a passo)</h3>
    <ol class="steps">
        <li>No Zscaler (ZIdentity), crie um <strong>cliente de API</strong> e copie seu <strong>Client ID</strong> e <strong>Client Secret</strong>. Anote seu <strong>domínio personalizado</strong> (a parte antes de <code>.zslogin.net</code>).</li>
        <li>No ZIA, crie (ou selecione) uma <strong>categoria de URL personalizada</strong> à qual os domínios bloqueados serão adicionados, e anote seu nome exato.</li>
        <li>No cartão <strong>Zscaler Connection</strong> da plataforma, marque <span class="field-label">Enable Zscaler URL-Category blocking on Deny</span>.</li>
        <li>Preencha <span class="field-label">API URL</span> (padrão <code>https://api.zsapi.net</code>), <span class="field-label">ZIdentity Vanity Domain</span>, <span class="field-label">Client ID</span>, <span class="field-label">Client Secret</span> e o nome da <span class="field-label">URL Category</span>.</li>
        <li>Clique em <span class="btn-label">Save Configuration</span> e depois em <span class="btn-label">Test Connection</span> para confirmar que as credenciais funcionam.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/zscaler-card.png" alt="Zscaler Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Zscaler Connection.</strong> Quando habilitado, o botão <strong>Deny</strong> em um aplicativo Shadow SaaS adiciona seu domínio à categoria de URL que você nomear aqui. A categoria já deve existir no Zscaler.</figcaption>
    </figure>
    <div class="callout callout-info">
        <strong>Se o bloqueio estiver desativado,</strong> negar um aplicativo apenas o marca como não autorizado na plataforma; nada é enviado ao Zscaler. Você verá <em>"Marked unsanctioned. Zscaler integration is not enabled; domain not added to URL Category."</em>
    </div>
</div>


<div class="doc-section" id="breach-alerts">
    <h2>Alertas de Violação / Cibernéticos <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>A página de <strong>Breach / Cyber Alerts</strong> reúne em um só lugar sinais de violação e de inteligência de ameaças para a cadeia de suprimentos de fornecedores. Abra-a na barra lateral em <span class="menu-label">Breach / Cyber Alerts</span> &rarr; <span class="menu-label">Breach Alerts</span>; um distintivo vermelho mostra o número de novos alertas.</p>

    <h3>De onde vêm os alertas</h3>
    <p>Os alertas incluem violações descobertas pelos scanners de Violação &amp; OSINT de IA (veja <a href="#admin-ai">Integração de IA</a>) e, quando habilitado, incidentes de segurança do <a href="#shadow-saas-grip">Grip</a>. Cada alerta mostra a entidade afetada, os usuários potencialmente impactados, a tecnologia e quando foi detectado. Um incidente em um aplicativo SaaS que você <strong>não</strong> integrou como fornecedor é marcado como <strong>&ldquo;Shadow SaaS&rdquo;</strong> com o número de usuários potencialmente impactados; se esse aplicativo for integrado posteriormente, incidentes futuros se anexam ao fornecedor.</p>

    <h3>Quem foi afetado</h3>
    <p>Para um incidente originado do Grip, a contagem de usuários impactados leva a uma lista de <strong>usuários afetados</strong> para aquele aplicativo. A lista é paginada e filtrável (por exemplo, por método de autenticação) e tem uma caixa <strong>Search by name or email</strong> para encontrar uma pessoa específica. Como a lista é armazenada criptografada, a busca é executada sobre os dados descriptografados no aplicativo, portanto funciona da mesma forma que a ordenação e a paginação.</p>

    <h3>Trabalhando os alertas em massa</h3>
    <p>Administradores e usuários Cyber TPRM têm uma barra de ferramentas de seleção múltipla na lista. Marque os alertas que deseja (ou use <strong>Check all</strong>) e aplique uma ação a todos eles de uma vez:</p>
    <ul>
        <li><span class="btn-label">Acknowledge</span> &mdash; marque os alertas como vistos.</li>
        <li><span class="btn-label">False Positive</span> &mdash; marque-os como não sendo um problema real.</li>
        <li><span class="btn-label">Delete</span> &mdash; remova-os. <strong>Somente administradores</strong>, e confirmado antes de executar.</li>
    </ul>
</div>


<!-- ================================================================
     ADMIN PORTAL
     ================================================================ -->
<div class="doc-section" id="admin-general">
    <h2>Portal Admin: Configurações Gerais</h2>
    <p>O Portal Admin é acessível via <span class="menu-label">Administration</span> na barra lateral (somente usuários admin) ou pelo link <span class="btn-label">Admin</span> na barra superior.</p>
    <p>As Configurações Gerais incluem: nome do aplicativo, nome da empresa, e-mail de suporte e opções de configuração em todo o sistema.</p>
</div>

<div class="doc-section" id="admin-branding">
    <h2>Identidade Visual &amp; Tema</h2>
    <p>Personalize a aparência da plataforma: faça upload do logotipo da sua empresa, defina cores da barra lateral, cores do cabeçalho, cores dos botões e largura da navegação. Navegue até <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Branding</span>.</p>
</div>

<div class="doc-section" id="admin-users">
    <h2>Gestão de Usuários</h2>
    <p>Gerencie contas de usuário e atribuições de grupo. Navegue até <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span>.</p>

    <h3>Atribuindo Usuários a Grupos ACL</h3>
    <ol class="steps">
        <li>Navegue até <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span>.</li>
        <li>Encontre o usuário na lista.</li>
        <li>Clique no botão <span class="btn-label">Groups</span> ao lado do nome do usuário.</li>
        <li>Um modal aparecerá mostrando todos os grupos disponíveis com caixas de seleção. Marque os grupos que deseja atribuir (ex.: <strong>Cyber GRC</strong>, <strong>Administrator</strong>).</li>
        <li>Clique em <span class="btn-label">Save Changes</span>.</li>
    </ol>
    <p>Além de atribuir os grupos fornecidos, os superadministradores podem criar seus próprios grupos com um conjunto de permissões personalizado &mdash; veja <a href="#admin-acl-groups">Grupos ACL &amp; Controle de Acesso Personalizado</a>.</p>
</div>

<div class="doc-section" id="admin-acl-groups">
    <h2>Grupos ACL &amp; Controle de Acesso Personalizado <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>A plataforma vem com sete grupos integrados (administrator, cyber_tprm, procurement, stakeholder, auditor, cyber_grc, grc_contributors). Na v2.6.2, os <strong>superadministradores</strong> também podem criar seus próprios grupos e ajustar exatamente o que cada um pode fazer. Abra <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Access Control</span> &rarr; <span class="menu-label">ACL Groups</span>. Qualquer administrador pode visualizar esta página; apenas superadministradores veem os controles de criação, edição e permissões.</p>

    <h3>Os grupos fornecidos são protegidos</h3>
    <p>Os sete grupos integrados são marcados como <strong>System</strong>. Eles não podem ser excluídos ou renomeados, e suas permissões são somente leitura &mdash; você pode abrir <span class="btn-label">View Permissions</span> para ver exatamente o que concedem, mas não alterá-las. Isso mantém estáveis os padrões dos quais todos dependem.</p>

    <h3>Criando um grupo personalizado</h3>
    <ol class="steps">
        <li>Clique em <span class="btn-label">+ Create Group</span>.</li>
        <li>Insira um <span class="field-label">Group Name (machine)</span> (letras minúsculas, números, sublinhados &mdash; isto é fixo depois de criado), um <span class="field-label">Display Name</span> amigável e uma <span class="field-label">Description</span>.</li>
        <li>Opcionalmente, use <span class="field-label">Copy permissions from</span> para <strong>clonar</strong> um grupo existente (incluindo um grupo System) como ponto de partida &mdash; depois refine-o. Deixe em <em>&mdash; Start with no permissions &mdash;</em> para construir do zero.</li>
        <li>Clique em <span class="btn-label">Create Group</span>.</li>
    </ol>

    <h3>Ajustando a matriz de permissões</h3>
    <p>Abra as <span class="btn-label">Permissions</span> de um grupo personalizado. As permissões são agrupadas por módulo (Vendor Onboarding, FAIR Analysis, Assessments, Security Rating (SRS), Annual Reviews, GRC e Other). Cada permissão é marcada como <strong>Read</strong> ou <strong>Read/Write</strong>, e cada módulo tem três predefinições de um clique:</p>
    <ul>
        <li><span class="btn-label">Read</span> &mdash; concede apenas as permissões de visualizar/listar/exportar daquele módulo.</li>
        <li><span class="btn-label">Read &amp; Write</span> &mdash; concede tudo (visualizar <em>e</em> alterar).</li>
        <li><span class="btn-label">None</span> &mdash; limpa o módulo.</li>
    </ul>
    <p>Clique em <span class="btn-label">Save Permissions</span> quando terminar. Conceder <strong>Read</strong> nunca implica acesso de escrita &mdash; a capacidade de alterar algo é sempre uma concessão separada e explícita. Todas as alterações de grupo são registradas em auditoria.</p>
</div>

<div class="doc-section" id="admin-templates">
    <h2>Construtor de Modelos de Avaliação <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>O <strong>Template Builder</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Template Builder</span>) é onde administradores e usuários Cyber TPRM projetam questionários de avaliação e integração. Use <span class="btn-label">+ Add Section</span> e <span class="btn-label">+ Add Question</span> para construir um modelo. Vale destacar algumas adições da v2.6.2.</p>

    <h3>Tipos de pergunta e mapeamento de campo</h3>
    <p>O <span class="field-label">Question Type</span> de uma pergunta agora inclui <strong>Phone</strong>, <strong>VAT Number</strong>, <strong>Checkboxes</strong> e <strong>Button Group (Multi)</strong> além dos familiares tipos de texto, menu suspenso e rádio (veja <a href="#custom-onboarding">Campos de Integração Personalizados</a> para o que cada um captura). O <span class="field-label">Field Name</span> de uma pergunta mapeia sua resposta para um campo de fornecedor; escolha um campo integrado ou um personalizado que você registrou em <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Field Reference</span>.</p>

    <h3>Certificate Upload Instructions</h3>
    <p>Em um modelo, você pode preencher <span class="field-label">Certificate Upload Instructions</span> &mdash; o texto exibido a um fornecedor no prompt <em>&ldquo;Do you have a Certificate?&rdquo;</em>. Isso permite que um modelo convide qualquer certificado (SOC 2 Type 2, ISO 27001 e assim por diante), não apenas ISO 27001. Se você deixar em branco, uma mensagem genérica é exibida.</p>

    <h3>Visibilidade por função em modelos de integração</h3>
    <p>Para modelos de <strong>integração</strong>, seções e perguntas personalizadas carregam controles <span class="field-label">Visible to Roles</span> e <span class="field-label">Visible and Editable Roles</span>, para que você decida quem pode ver e editar cada campo personalizado. Veja <a href="#custom-onboarding">Campos de Integração Personalizados &amp; a Aba Custom Data</a>.</p>

    <h3>Modelos desativados ficam ocultos por padrão</h3>
    <p>A lista de modelos mostra apenas modelos <strong>ativos</strong>. Se algum tiver sido desativado, um botão <span class="btn-label">Show Deactivated (N)</span> os revela (e alterna de volta para <span class="btn-label">Hide Deactivated (N)</span>), mantendo a lista de um locatário de longa duração focada nos modelos realmente em uso sem perder o acesso aos aposentados.</p>
</div>

<div class="doc-section" id="admin-email">
    <h2>Configuração de E-mail</h2>
    <p>Configure as configurações SMTP para enviar notificações por e-mail. Navegue até <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email</span>. As configurações incluem host SMTP, porta, nome de usuário, senha, método de criptografia (TLS/SSL) e endereço do remetente.</p>
</div>

<div class="doc-section" id="admin-saml">
    <h2>SAML / SSO</h2>
    <p>Configure o Single Sign-On usando SAML 2.0. Navegue até <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span>. Isso permite que os usuários façam login usando o provedor de identidade da sua organização (Okta, Azure AD, etc.).</p>
    <ol class="steps">
        <li>Marque <span class="field-label">Enable SAML 2.0</span>.</li>
        <li>Preencha todos os campos obrigatórios do Provedor de Identidade (<span class="field-label">IdP Entity ID</span>, <span class="field-label">IdP Single Sign-On URL</span>, <span class="field-label">IdP X.509 Certificate</span>) e do Provedor de Serviço (<span class="field-label">SP Entity ID</span>, <span class="field-label">SP ACS URL</span>). O SSO só é ativado quando <strong>todos</strong> eles estiverem preenchidos &mdash; um formulário parcialmente preenchido permanece desativado.</li>
        <li>Clique em <span class="btn-label">Save</span>.</li>
    </ol>

    <h3>Executando login local e SSO juntos</h3>
    <p>Por padrão, habilitar o SSO <strong>não</strong> desativa o formulário local de usuário/senha &mdash; a página de login mostra um botão <strong>Sign in with SSO</strong> <em>e</em> uma opção de login local, portanto ambos funcionam lado a lado. O comportamento é controlado por um único interruptor de login local:</p>
    <table>
        <tr><th>Modo</th><th>O Que os Usuários Veem</th></tr>
        <tr><td><strong>Login local habilitado</strong> (padrão)</td><td>Botão SSO <em>e</em> o formulário de usuário/senha. Use isso para executar ambos ao mesmo tempo.</td></tr>
        <tr><td><strong>Login local desabilitado</strong> (somente SSO)</td><td>O SSO é o único caminho para usuários normais. A conta de <strong>admin de break-glass</strong> designada ainda pode fazer login localmente, para que um provedor de identidade com falha nunca bloqueie todos.</td></tr>
    </table>
    <p>Se o SAML não estiver realmente configurado, o interruptor é ignorado e o login local sempre permanece disponível (rede de segurança anti-bloqueio).</p>

    <h3>Break-glass: permitir SAML e login local juntos (arquivo de configuração) <span class="new-badge">Novo em 2.6.2</span></h3>
    <p>O interruptor de login local pode ser definido de duas maneiras. A configuração do arquivo de configuração, quando presente, <strong>tem precedência sobre o valor do banco de dados</strong> &mdash; um controle de break-glass que não precisa de acesso ao banco de dados, para que você sempre possa restaurar o login local mesmo que o SSO esteja com problemas.</p>
    <table>
        <tr><th>Onde</th><th>Como</th></tr>
        <tr><td>Admin &rarr; página SAML</td><td>No cartão <strong>Connection Settings</strong>, marque ou desmarque <span class="field-label">Allow local username/password login (in addition to SSO)</span> e clique em <span class="btn-label">Save SAML Configuration</span>. Isso grava a configuração <code>local_login_enabled</code> (habilitada por padrão) &mdash; sem necessidade de SQL.</td></tr>
        <tr><td>Arquivo de configuração (prevalece se definido)</td><td>Em <code>config/config.php</code>, no bloco <code>auth</code>, defina <code>'local_login_enabled' =&gt; true</code> para manter o login local sempre disponível (tanto local quanto SSO), ou <code>false</code> para somente SSO. Isso <strong>substitui</strong> o interruptor acima; enquanto está definido, a caixa de seleção na página SAML é exibida como somente leitura. Remova a linha para gerenciá-la pela interface novamente. Reinicie o container após editar <code>config.php</code>.</td></tr>
    </table>
    <p>Para executar <strong>tanto o SAML quanto o login local</strong> sem alterações no banco de dados, configure o SAML como acima e adicione isto ao bloco <code>auth</code> de <code>config/config.php</code>, depois reinicie o container:</p>
    <pre><code>'auth' =&gt; [
    // ...
    // Break-glass: true = local login always available alongside SSO;
    // false = SSO-only (break-glass admin can still log in locally).
    'local_login_enabled' =&gt; true,
],</code></pre>
</div>

<div class="doc-section" id="admin-ai">
    <h2>Integração de IA</h2>
    <p>Habilite recursos com IA, incluindo refinamento de notas de avaliação, sugestões de controle, comentários sobre fornecedores, análise de risco FAIR assistida por IA e assistência de idioma em relatórios. Navegue até <span class="menu-label">Admin</span> &rarr; <span class="menu-label">AI Platform</span> para escolher um provedor e inserir sua chave de API. Apenas uma plataforma está ativa por vez.</p>
    <p>Plataformas de IA suportadas:</p>
    <ul>
        <li><strong>Anthropic (Claude)</strong> <span class="new-badge">Novo em 2.6.2</span> &mdash; conecta-se diretamente à API nativa do Claude (ex.: <code>claude-opus-4-8</code>). Cole sua chave de API Anthropic; o endpoint usa por padrão a URL padrão de Mensagens. Suporta <strong>pesquisa web</strong> ao vivo, portanto os Alertas de Violação e varreduras OSINT são fundamentados em fontes atuais e citadas.</li>
        <li><strong>OpenAI (ChatGPT)</strong> <span class="new-badge">Novo em 2.6.2</span> &mdash; conecta-se diretamente ao OpenAI (ex.: <code>gpt-4o</code>). Cole sua chave de API OpenAI. Para varreduras de Violação &amp; OSINT, usa um modelo com capacidade de pesquisa web (padrão <code>gpt-4o-search-preview</code>) para que essas varreduras sejam fundamentadas em fontes ao vivo.</li>
        <li><strong>OpenWebUI</strong> &mdash; token bearer JWT contra um endpoint compatível com OpenAI.</li>
        <li><strong>LibreChat</strong> &mdash; autenticação por chave de API, baseada em agente; o agente gerencia seu próprio modelo e amostragem.</li>
        <li><strong>Custom</strong> &mdash; cole um modelo de cabeçalhos + corpo estilo curl para qualquer outro endpoint compatível com OpenAI (ou orquestrador).</li>
    </ul>
    <p><strong>Escolhendo &amp; carregando um modelo:</strong> após inserir e <strong>salvar</strong> uma chave, clique em <span class="btn-label">Load Models</span> no cartão daquela plataforma para buscar sua lista de modelos disponíveis (OpenWebUI / LibreChat / OpenAI). Para Anthropic, digite o nome do modelo diretamente (ex.: <code>claude-opus-4-8</code>).</p>
    <p><strong>Fundamentação de Alertas de Violação:</strong> os scanners de Violação &amp; OSINT precisam de um provedor capaz de pesquisar na web. <strong>Anthropic (Claude)</strong> e <strong>OpenAI (ChatGPT)</strong> fundamentam nativamente; OpenWebUI / LibreChat fundamentam apenas se o agente subjacente tiver navegação; a plataforma Custom fundamenta apenas quando uma URL de Pesquisa Web está configurada.</p>
    <p>O provedor de IA ativo também alimenta a <strong>tradução automática das perguntas de avaliação</strong> (veja <a href="#language">Alterando Seu Idioma</a>).</p>
</div>

<div class="doc-section" id="admin-updates">
    <h2>Atualizando a Plataforma <span class="new-badge">Novo em 2.6.2</span></h2>
    <p>Os administradores podem verificar e aplicar novas versões de dentro da plataforma. Navegue até <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span>.</p>
    <ol class="steps">
        <li>O cartão <strong>Current Status</strong> mostra sua <span class="field-label">Installed Version</span> e se há uma versão mais recente disponível.</li>
        <li>Confirme que o <span class="field-label">Registry Hostname</span> está correto (seu registro de imagens) e clique em <span class="btn-label">Check for Updates</span>.</li>
        <li>Se uma versão mais recente estiver listada, siga a ação de <strong>Upgrade</strong> na tela para aplicá-la.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-version.png?v=20260711" alt="Version Management page showing installed version 2.6.2" loading="lazy">
        <figcaption><strong>Admin &rarr; Version.</strong> Aqui a versão instalada é <strong>v2.6.2</strong> e a plataforma informa que está atualizada. Este também é o lugar onde você confirma a qual versão este guia se aplica.</figcaption>
    </figure>
</div>


<!-- ================================================================
     FAQ (SEARCHABLE)
     ================================================================ -->
<div class="doc-section" id="faq">
    <h2>Perguntas Frequentes</h2>
    <p>Digite uma palavra-chave abaixo para filtrar as perguntas instantaneamente &mdash; por exemplo <em>idioma</em>, <em>VID</em>, <em>integração</em>, <em>Grip</em> ou <em>senha</em>.</p>
    <div class="faq-search-wrap">
        <input type="text" id="faqSearch" class="faq-search" placeholder="Pesquisar nas perguntas frequentes&hellip;" aria-label="Search frequently asked questions" autocomplete="off">
    </div>
    <p class="faq-count" id="faqCount"></p>
    <div id="faqList">

        <details class="faq-item"><summary>Os usuários podem fazer login com SSO e uma senha local ao mesmo tempo?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>Sim. Habilitar SAML/SSO <strong>não</strong> desativa o login local por padrão &mdash; a página de login mostra um botão <strong>Sign in with SSO</strong> e uma opção de usuário/senha local juntos. Você controla isso com a caixa de seleção <strong>Allow local username/password login</strong> na página <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span> (deixe marcada para executar ambos; desmarque para somente SSO, onde o admin de break-glass ainda pode fazer login localmente). Para um controle de break-glass sem banco de dados, a mesma configuração pode ser forçada em <code>config/config.php</code> via <code>'local_login_enabled' =&gt; true</code>, que substitui a caixa de seleção. Veja <a href="#admin-saml">SAML / SSO</a>.</p></div></details>

        <details class="faq-item"><summary>O SSO está mal configurado e ninguém consegue fazer login. Como faço para entrar novamente?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>Use o controle de break-glass: em <code>config/config.php</code>, no bloco <code>auth</code>, defina <code>'local_login_enabled' =&gt; true</code> e reinicie o container. Isso reativa o formulário local de usuário/senha independentemente da configuração do banco de dados, para que você possa fazer login e corrigir a configuração SAML. Veja <a href="#admin-saml">SAML / SSO</a>.</p></div></details>

        <details class="faq-item"><summary>Como altero o idioma da plataforma?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>Clique em <strong>Profile</strong> (canto superior direito), abra o cartão <strong>Language Preference</strong>, escolha seu idioma e clique em <strong>Update Language</strong>. Isso altera apenas a sua própria tela. Veja <a href="#language">Alterando Seu Idioma</a>.</p></div></details>

        <details class="faq-item"><summary>Quais idiomas são suportados?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>Inglês, Espanhol, Italiano, Ucraniano, Chinês (Simplificado), Hindi, Francês e Português. Seu administrador decide quais desses aparecem na sua lista; o inglês está sempre disponível.</p></div></details>

        <details class="faq-item"><summary>Alterei meu idioma, mas alguns textos ainda estão em inglês. Por quê?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>Algumas coisas diferentes podem permanecer em inglês mesmo depois de você trocar de idioma:</p>
            <ul>
                <li><strong>Texto da interface que ainda não foi traduzido.</strong> Menus, botões e rótulos são traduzidos sempre que existe uma tradução para o seu idioma. Se uma determinada string ainda não foi traduzida para o seu idioma, ela recorre ao inglês em vez de exibir um espaço em branco &mdash; portanto você pode ver algum rótulo ocasional em inglês.</li>
                <li><strong>Qualquer coisa que foi digitada.</strong> O conteúdo que você ou seus fornecedores inserem &mdash; nomes de fornecedores, notas, nomes de documentos enviados, respostas de texto livre &mdash; é exibido exatamente como foi escrito, em qualquer idioma que tenha sido.</li>
                <li><strong>Perguntas de avaliação sem um provedor de IA.</strong> O texto das <em>perguntas</em> de avaliação de fornecedores é traduzido automaticamente apenas quando o seu administrador configurou um provedor de IA; sem um, as perguntas permanecem no idioma em que foram redigidas. Os valores de resposta armazenados sempre permanecem em inglês para que a pontuação permaneça consistente.</li>
                <li><strong>E-mails e alguns componentes de terceiros</strong> não são controlados pela sua configuração de idioma.</li>
            </ul>
            <p>Se você vir um rótulo de interface que deveria estar traduzido, mas não está, avise o seu administrador para que o texto ausente possa ser adicionado.</p></div></details>

        <details class="faq-item"><summary>Por que não consigo enviar meu fornecedor para revisão?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>Um fornecedor só pode ser enviado quando tiver concluído a <strong>Procurement Onboarding</strong> (definida como <strong>Yes</strong>) e tiver um <strong>Vendor ID (VID)</strong> válido de 4&ndash;8 dígitos. Abra o fornecedor, preencha ambos no cartão <strong>Vendor Information</strong>, salve e clique em <strong>Submit for Review</strong>. Veja <a href="#onboarding-workflow">Integração de Fornecedor</a> e <a href="#troubleshooting">Solução de Problemas</a>.</p></div></details>

        <details class="faq-item"><summary>O que é um Vendor ID (VID) e onde o obtenho?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>O VID é um número de 4&ndash;8 dígitos atribuído ao fornecedor pelo seu sistema de compras quando o fornecedor é integrado. Ele vincula o fornecedor aqui aos seus registros de compras e financeiros. Se você não tem um, o fornecedor ainda não concluiu a integração de compras.</p></div></details>

        <details class="faq-item"><summary>O campo no meu fornecedor diz "VSU Onboarded", mas o guia diz "Procurement Onboarding". Qual é o correto?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>São o mesmo campo. Foi renomeado para o mais claro <strong>"Procurement Onboarding"</strong> na v2.6.2. Se a sua tela ainda mostra <strong>"VSU Onboarded"</strong>, sua instância ainda não foi atualizada para a imagem mais recente da v2.6.2 &mdash; o comportamento é idêntico.</p></div></details>

        <details class="faq-item"><summary>O que significa "AI Review" para um fornecedor?<span class="faq-tag">AI Review</span></summary>
            <div class="faq-body"><p>É um status de revisão separado para fornecedores cujos serviços usam IA, para que possam ser rastreados separadamente das revisões comuns. Um usuário Cyber TPRM ou admin move um fornecedor para ele com o link <strong>Force AI Review</strong>. Veja <a href="#ai-review">Revisão de IA para Fornecedores</a>.</p></div></details>

        <details class="faq-item"><summary>Não vejo o link "Force AI Review". Por quê?<span class="faq-tag">AI Review</span></summary>
            <div class="faq-body"><p>Ele só aparece quando você está editando o fornecedor com permissão de aprovação, o campo <strong>Services Use AI</strong> do fornecedor é <strong>Yes</strong> e o fornecedor ainda não está em AI Review.</p></div></details>

        <details class="faq-item"><summary>O que é a página Procurement Cyber Status?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>Uma página em linguagem simples (<strong>TPRM &rarr; Procurement &rarr; Cyber Status</strong>) onde compras pode ver quais fornecedores a equipe cibernética está revisando e ler atualizações com data que a equipe cibernética publica. Veja <a href="#procurement-cyber-status">Status Cibernético de Compras</a>.</p></div></details>

        <details class="faq-item"><summary>Como compras recebe e-mails de atualização?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>Um administrador ativa o <strong>Procurement Update Digest</strong> em <strong>Admin &rarr; Email Settings</strong> e adiciona endereços de destinatários. É enviado por e-mail semanalmente (por padrão, segundas-feiras às 7:00) e também pode ser enviado sob demanda.</p></div></details>

        <details class="faq-item"><summary>O que é o Grip e o que ele faz aqui?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>O Grip Security descobre aplicativos SaaS usados em toda a sua organização. Quando conectado (<strong>Admin &rarr; Shadow SaaS</strong>), a plataforma puxa automaticamente esses aplicativos, suas contagens de usuários, pontuações de risco e alertas para sua lista Shadow SaaS. Veja <a href="#shadow-saas-grip">Integração Grip Shadow SaaS</a>.</p></div></details>

        <details class="faq-item"><summary>Qual é a diferença entre as integrações Grip e Hero?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Ambas alimentam a mesma lista de Shadow SaaS a partir de um serviço de descoberta de terceiros &mdash; Grip Security ou HERO Security &mdash; e ambas compartilham o bloqueio Zscaler e o trabalho de Rehydration Agendada. Elas são <strong>mutuamente exclusivas</strong>: habilitar uma desativa a outra, portanto você executa o provedor que sua organização usa. Veja <a href="#shadow-saas-hero">Integração Hero Shadow SaaS</a>.</p></div></details>

        <details class="faq-item"><summary>Meu "Test Connection" do Grip falhou. O que devo verificar?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Confirme que o <strong>Server (Tenant Base URL)</strong> termina em <code>/public/saas</code>, que o <strong>API Token</strong> está atualizado e que seu servidor pode alcançar o endpoint do Grip. Um erro de token reporta <em>"Unauthorized — token rejected"</em>; um erro de URL reporta <em>"Endpoint not found — check base URL"</em>.</p></div></details>

        <details class="faq-item"><summary>O que a integração Zscaler faz?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Quando você <strong>Deny</strong> um aplicativo não autorizado, a plataforma pode adicionar seu domínio web a uma categoria de URL de bloqueio na sua conta Zscaler para que as pessoas não possam acessá-lo. Clicar em <strong>Allow</strong> posteriormente remove o bloqueio. Veja <a href="#zscaler">Integração de Bloqueio Zscaler</a>.</p></div></details>

        <details class="faq-item"><summary>Qual é a diferença entre Allow, Deny e Dismiss em um aplicativo Shadow SaaS?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p><strong>Allow</strong> inicia a integração do aplicativo como fornecedor; <strong>Deny</strong> o marca como não autorizado (e pode bloqueá-lo no Zscaler); <strong>Dismiss</strong> o oculta da lista. Aplicativos dispensados permanecem dispensados mesmo após sincronizações futuras.</p></div></details>

        <details class="faq-item"><summary>Quem pode ver o módulo GRC?<span class="faq-tag">Access</span></summary>
            <div class="faq-body"><p>Usuários nos grupos <strong>Administrator</strong>, <strong>Cyber GRC</strong> ou <strong>Auditor</strong>. Se não o vir, peça ao seu administrador para adicioná-lo a um desses grupos. Veja <a href="#roles">Funções de Usuário &amp; Permissões</a>.</p></div></details>

        <details class="faq-item"><summary>Como ativo a autenticação de dois fatores (2FA)?<span class="faq-tag">Account</span></summary>
            <div class="faq-body"><p>Abra <strong>Profile</strong> e use o cartão <strong>Two-Factor Authentication (TOTP)</strong> para ativá-la com um aplicativo autenticador como Google Authenticator ou Microsoft Authenticator.</p></div></details>

        <details class="faq-item"><summary>Posso salvar ou imprimir esta documentação?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>Sim. Clique em <strong>Download PDF</strong> no topo desta página. Isso produz um documento formatado com uma página de capa, índice e números de página.</p></div></details>

        <details class="faq-item"><summary>Como sei qual versão estou executando?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>Os administradores podem verificar em <strong>Admin &rarr; Version</strong>. Este guia descreve a <strong>v2.6.2</strong>. Veja <a href="#admin-updates">Atualizando a Plataforma</a>.</p></div></details>

    </div>
    <p class="faq-no-results" id="faqNoResults">Nenhuma pergunta corresponde à sua pesquisa. Tente uma palavra-chave diferente.</p>
</div>


<!-- ================================================================
     TROUBLESHOOTING
     ================================================================ -->
<div class="doc-section" id="troubleshooting">
    <h2>Solução de Problemas</h2>

    <h3>Por que a integração via compras é importante (a regra de VID &amp; Procurement Onboarding)</h3>
    <p>Esta é a única coisa mais comum que impede um fornecedor de avançar, então vale a pena entender. A plataforma <strong>não permitirá que um fornecedor seja enviado para revisão cibernética</strong> até que dois dados de compras sejam registrados no fornecedor:</p>
    <ul>
        <li><strong>Procurement Onboarding = Yes</strong> &mdash; confirmação de que o fornecedor foi configurado e verificado pelo processo de compras da sua organização.</li>
        <li>Um <strong>Vendor ID (VID)</strong> válido &mdash; o número de 4&ndash;8 dígitos que compras atribui ao fornecedor.</li>
    </ul>
    <p>Por que impor isso? Porque o VID é a chave compartilhada que vincula este fornecedor aos registros de compras, finanças e contratos. Se a equipe cibernética revisasse e aprovasse um fornecedor que compras nunca integrou, você acabaria com fornecedores duplicados ou "fantasmas", trabalho de segurança que não pode ser vinculado a uma ordem de compra real e relatórios que não conciliam. Exigir a integração de compras <em>primeiro</em> mantém a revisão de segurança e o registro de compras apontando para o mesmo fornecedor real.</p>
    <div class="callout callout-warning">
        <strong>Corrija:</strong> Abra o fornecedor e, no cartão <strong>Vendor Information</strong>, defina <strong>Procurement Onboarding</strong> como <strong>Yes</strong> e insira o <strong>Vendor ID (VID)</strong> de 4&ndash;8 dígitos do seu sistema de compras. Salve e clique em <strong>Submit for Review</strong> novamente. Se você ainda não tem um VID, o fornecedor não concluiu a integração de compras &mdash; comece por aí.
    </div>

    <h3>Problemas comuns e como resolvê-los</h3>
    <table class="doc-table">
        <tr><th>Sintoma</th><th>Causa provável &amp; solução</th></tr>
        <tr><td>"Cannot submit: Vendor must be onboarded at VSU before submission&hellip;"</td><td>O campo <strong>Procurement Onboarding</strong> não está definido como <strong>Yes</strong>. Defina como Yes no cartão Vendor Information e salve.</td></tr>
        <tr><td>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits)&hellip;"</td><td>O <strong>Vendor ID</strong> está ausente ou não tem 4&ndash;8 dígitos. Insira um VID válido de compras.</td></tr>
        <tr><td>"Only draft requests can be submitted for review."</td><td>O fornecedor já passou de Draft. Você só pode enviar uma solicitação que ainda está com status <strong>Draft</strong>.</td></tr>
        <tr><td>O botão <strong>Submit for Review</strong> não está visível</td><td>Ele só aparece para fornecedores em <strong>Draft</strong> quando você tem permissão de edição.</td></tr>
        <tr><td>"AI Review can only be forced for vendors whose services use AI."</td><td>Defina <strong>Services Use AI</strong> como <strong>Yes</strong> no fornecedor antes de forçar o AI Review.</td></tr>
        <tr><td>Não consigo ver o módulo GRC na barra lateral</td><td>Você precisa estar no grupo <strong>Administrator</strong>, <strong>Cyber GRC</strong> ou <strong>Auditor</strong>. Fale com um administrador.</td></tr>
        <tr><td>Minha alteração de idioma não foi salva</td><td>Certifique-se de ter clicado em <strong>Update Language</strong> (não apenas alterado o menu suspenso), e que o idioma está habilitado pelo seu administrador.</td></tr>
        <tr><td>O "Test Connection" do Grip falha</td><td>Verifique se a URL base termina em <code>/public/saas</code> e se o token de API é válido e atual.</td></tr>
        <tr><td>Negar um aplicativo Shadow SaaS não o bloqueou no Zscaler</td><td>O bloqueio Zscaler deve estar habilitado e configurado, e a <strong>URL Category</strong> nomeada já deve existir no Zscaler.</td></tr>
        <tr><td>Compras não recebeu o e-mail de resumo</td><td>Confirme que o resumo está habilitado com destinatários em <strong>Admin &rarr; Email Settings</strong> e que as configurações SMTP em <strong>Admin &rarr; Email</strong> estão corretas.</td></tr>
        <tr><td>O botão Upgrade diz "Could not fetch manifest"</td><td>Um problema de registro/rede ao alcançar seu registro de imagens. Verifique o <strong>Registry Hostname</strong> em <strong>Admin &rarr; Version</strong> e se o host pode alcançá-lo.</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>Ainda com problemas?</strong> Anote a mensagem exata na tela e em qual página você estava, depois entre em contato com o administrador da plataforma. Os administradores podem revisar <strong>Admin &rarr; Activity Log</strong> para obter detalhes.
    </div>
</div>


<!-- ================================================================
     GLOSSARY
     ================================================================ -->
<div class="doc-section" id="glossary">
    <h2>Glossário</h2>
    <table class="doc-table">
        <tr><th>Termo</th><th>Definição</th></tr>
        <tr><td><strong>ACL</strong></td><td>Access Control List &mdash; define quais ações os usuários de um grupo podem realizar</td></tr>
        <tr><td><strong>Action Plan</strong></td><td>Uma aba por fornecedor para agendar ações de acompanhamento (contatar, enviar avaliação, forçar revisão anual) com datas de prazo, responsáveis e notas de status; disparada diariamente pelo trabalho Vendor Remediation Schedule</td></tr>
        <tr><td><strong>AI Review</strong></td><td>Um status de integração de fornecedor para fornecedores cujos serviços usam IA, rastreado separadamente durante a revisão</td></tr>
        <tr><td><strong>Assessment</strong></td><td>Uma avaliação de conformidade em um ponto no tempo usando o questionário unificado</td></tr>
        <tr><td><strong>CIS Controls</strong></td><td>Center for Internet Security Controls &mdash; um conjunto priorizado de melhores práticas de segurança</td></tr>
        <tr><td><strong>CMMC</strong></td><td>Cybersecurity Maturity Model Certification &mdash; exigido para contratantes do Departamento de Defesa dos EUA</td></tr>
        <tr><td><strong>Conformity Status</strong></td><td>Se um requisito é Conforming, Partial, Non-Conforming, Not Applicable ou Not Assessed</td></tr>
        <tr><td><strong>Control</strong></td><td>Uma medida de segurança específica implementada para atender aos requisitos de conformidade</td></tr>
        <tr><td><strong>Crosswalk</strong></td><td>Um mapeamento entre dois frameworks mostrando quais requisitos se sobrepõem</td></tr>
        <tr><td><strong>CSF</strong></td><td>NIST Cybersecurity Framework &mdash; um framework amplamente utilizado de gestão de risco de cibersegurança</td></tr>
        <tr><td><strong>Custom Field / Custom Data</strong></td><td>Um campo de integração específico da organização sem uma coluna padrão de fornecedor; capturado por fornecedor e exibido na aba Custom Data do fornecedor, com visibilidade por função (veja <a href="#custom-onboarding">Campos de Integração Personalizados</a>)</td></tr>
        <tr><td><strong>Domain</strong></td><td>Uma categoria de perguntas de segurança (ex.: Governance, Identity &amp; Access Management)</td></tr>
        <tr><td><strong>Evidence</strong></td><td>Documentos, capturas de tela ou arquivos que comprovam uma afirmação de conformidade</td></tr>
        <tr><td><strong>FAIR</strong></td><td>Factor Analysis of Information Risk &mdash; uma metodologia quantitativa de análise de risco</td></tr>
        <tr><td><strong>FairScore</strong></td><td>A pontuação geral de maturidade da plataforma calculada a partir das respostas de avaliação</td></tr>
        <tr><td><strong>Finding</strong></td><td>Um problema descoberto durante uma auditoria (não conformidade, observação, oportunidade ou ponto forte)</td></tr>
        <tr><td><strong>Framework</strong></td><td>Um padrão de conformidade como SOC 2, ISO 27001, PCI DSS, etc.</td></tr>
        <tr><td><strong>GRC</strong></td><td>Governance, Risk, and Compliance (Governança, Risco e Conformidade)</td></tr>
        <tr><td><strong>Grip</strong></td><td>Grip Security &mdash; um serviço que descobre aplicativos SaaS em uso; pode alimentar a lista de Shadow SaaS (veja <a href="#shadow-saas-grip">Integração Grip Shadow SaaS</a>)</td></tr>
        <tr><td><strong>Hero</strong></td><td>HERO Security &mdash; um serviço alternativo de descoberta de Shadow SaaS que pode alimentar a lista de Shadow SaaS (mutuamente exclusivo com Grip; veja <a href="#shadow-saas-hero">Integração Hero Shadow SaaS</a>)</td></tr>
        <tr><td><strong>HIPAA</strong></td><td>Health Insurance Portability and Accountability Act &mdash; lei de proteção de dados de saúde dos EUA</td></tr>
        <tr><td><strong>ISO 27001</strong></td><td>Padrão internacional para sistemas de gestão de segurança da informação</td></tr>
        <tr><td><strong>Maturity Rating</strong></td><td>Uma pontuação de 1-4 indicando quão madura é uma prática de segurança (1=Ad Hoc, 4=Optimized)</td></tr>
        <tr><td><strong>NIST 800-171</strong></td><td>Diretrizes NIST para proteção de Informações Não Classificadas Controladas (CUI)</td></tr>
        <tr><td><strong>PCI DSS</strong></td><td>Payment Card Industry Data Security Standard</td></tr>
        <tr><td><strong>PII</strong></td><td>Personally Identifiable Information &mdash; Informações Pessoalmente Identificáveis (nomes, e-mails, endereços, etc.)</td></tr>
        <tr><td><strong>Procurement Onboarding</strong></td><td>Confirmação (Yes/No) de que um fornecedor foi configurado pelo seu processo de compras; necessária, junto com um VID válido, antes que um fornecedor possa ser enviado para revisão. (Rotulado como "VSU Onboarded" em instâncias atualizadas de versões anteriores.)</td></tr>
        <tr><td><strong>Requirement</strong></td><td>Uma cláusula específica ou objetivo de controle dentro de um framework de conformidade</td></tr>
        <tr><td><strong>SaaS</strong></td><td>Software as a Service &mdash; aplicativos em nuvem acessados pela web</td></tr>
        <tr><td><strong>Shadow SaaS</strong></td><td>Aplicativos SaaS usados na organização que nunca foram formalmente aprovados ou avaliados</td></tr>
        <tr><td><strong>SOC 2</strong></td><td>Service Organization Control Type 2 &mdash; critérios de serviços de confiança para organizações de serviço</td></tr>
        <tr><td><strong>SPII</strong></td><td>PII Sensível (CPFs, dados financeiros, registros de saúde)</td></tr>
        <tr><td><strong>SRS</strong></td><td>Security Risk Scorecard &mdash; a classificação/nota de segurança externa da plataforma para um fornecedor</td></tr>
        <tr><td><strong>SSC (SecurityScorecard)</strong></td><td>Uma nota de segurança em letra de terceiros (A&ndash;F) exibida para aplicativos e fornecedores descobertos pelo Grip quando o Grip está conectado</td></tr>
        <tr><td><strong>Subprocessor</strong></td><td>O próprio fornecedor a jusante de um fornecedor; o mesmo subprocessador compartilhado por vários dos seus fornecedores indica concentração da cadeia de suprimentos (veja <a href="#tprm-fourth-party">Risco de 4ª Parte</a>)</td></tr>
        <tr><td><strong>TPRM</strong></td><td>Third Party Risk Management (Gestão de Risco de Terceiros)</td></tr>
        <tr><td><strong>Unified Question</strong></td><td>Uma única pergunta de segurança que mapeia para requisitos em múltiplos frameworks</td></tr>
        <tr><td><strong>VID</strong></td><td>Vendor ID &mdash; um identificador de 4&ndash;8 dígitos atribuído a um fornecedor pelo seu sistema de compras</td></tr>
        <tr><td><strong>VSU</strong></td><td>A função de configuração de compras/fornecedor; "onboarded at VSU" significa que o fornecedor concluiu a Integração de Compras</td></tr>
        <tr><td><strong>Zscaler</strong></td><td>Um serviço de segurança web que pode bloquear domínios de sites; integrado para que aplicativos não autorizados possam ser bloqueados ao negar (veja <a href="#zscaler">Bloqueio Zscaler</a>)</td></tr>
    </table>
</div>


                </div><!-- /.doc-body -->

                <!-- Sticky right-side TOC (screen only) -->
                <nav class="doc-sidebar-toc" id="sidebarToc">
                    <h4>Primeiros Passos</h4>
                    <a href="#overview">Visão Geral da Plataforma</a>
                    <a href="#navigation">Navegando pela Barra Lateral</a>
                    <a href="#roles">Funções de Usuário &amp; Permissões</a>
                    <a href="#first-login">Seu Primeiro Login</a>
                    <a href="#whats-new">O Que Há de Novo em 2.6.2</a>
                    <a href="#language">Alterando Seu Idioma</a>
                    <a href="#question-types">Tipos de Pergunta Telefone &amp; VAT</a>

                    <h4>GRC — Início Rápido</h4>
                    <a href="#grc-overview">O Que é GRC?</a>
                    <a href="#grc-getting-started">Primeiros Passos</a>
                    <a href="#grc-step1">Etapa 1: Criar Avaliação</a>
                    <a href="#grc-step2">Etapa 2: Responder Perguntas</a>
                    <a href="#grc-step3">Etapa 3: Fazer Upload de Evidências</a>
                    <a href="#grc-step4">Etapa 4: Ver Pontuações</a>
                    <a href="#grc-step5">Etapa 5: Gerar Relatório</a>

                    <h4>GRC — Recursos</h4>
                    <a href="#grc-fairscore">Pontuação de Maturidade CSF</a>
                    <a href="#grc-gaps">Análise de Lacunas</a>
                    <a href="#grc-frameworks">Frameworks</a>
                    <a href="#grc-controls">Controles Internos</a>
                    <a href="#grc-crosswalk">Crosswalk de Frameworks</a>
                    <a href="#grc-evidence">Biblioteca de Evidências</a>
                    <a href="#grc-policies">Gestão de Políticas</a>
                    <a href="#grc-audits">Auditorias &amp; Constatações</a>
                    <a href="#grc-risks">Registro de Riscos</a>
                    <a href="#grc-monitors">Monitores Contínuos</a>
                    <a href="#grc-tasks">Caixa de Entrada de Tarefas</a>
                    <a href="#grc-dashboard">Painel GRC</a>

                    <h4>Módulo TPRM</h4>
                    <a href="#tprm-overview">O Que é TPRM?</a>
                    <a href="#tprm-add-vendor">Adicionando um Fornecedor</a>
                    <a href="#tprm-lifecycle">Ciclo de Vida do Fornecedor</a>
                    <a href="#tprm-assessments">Avaliações de Fornecedores</a>
                    <a href="#assessment-forms">Formulários de Avaliação &amp; IA</a>
                    <a href="#tprm-action-plan">Plano de Ação do Fornecedor</a>
                    <a href="#tprm-srs">Scorecard de Risco de Segurança</a>
                    <a href="#tprm-fair">Análise FAIR</a>
                    <a href="#tprm-fourth-party">Risco de 4ª Parte</a>
                    <a href="#tprm-shadow-saas">Shadow SaaS</a>

                    <h4>Integração &amp; Compras</h4>
                    <a href="#onboarding-workflow">Integração de Fornecedor</a>
                    <a href="#custom-onboarding">Campos de Integração Personalizados</a>
                    <a href="#ai-review">AI Review</a>
                    <a href="#procurement-cyber-status">Status Cibernético de Compras</a>
                    <a href="#shadow-saas-grip">Integração Grip Shadow SaaS</a>
                    <a href="#shadow-saas-hero">Integração Hero Shadow SaaS</a>
                    <a href="#zscaler">Bloqueio Zscaler</a>
                    <a href="#breach-alerts">Alertas de Violação / Cibernéticos</a>

                    <h4>Portal Admin</h4>
                    <a href="#admin-general">Configurações Gerais</a>
                    <a href="#admin-branding">Identidade Visual &amp; Tema</a>
                    <a href="#admin-users">Gestão de Usuários</a>
                    <a href="#admin-acl-groups">Grupos ACL</a>
                    <a href="#admin-templates">Modelos de Avaliação</a>
                    <a href="#admin-email">Configuração de E-mail</a>
                    <a href="#admin-saml">SAML / SSO</a>
                    <a href="#admin-ai">Integração de IA</a>
                    <a href="#admin-backup">Backups de Banco de Dados Grandes</a>
                    <a href="#admin-updates">Atualizando a Plataforma</a>

                    <h4>Ajuda &amp; Referência</h4>
                    <a href="#faq">Perguntas Frequentes</a>
                    <a href="#troubleshooting">Solução de Problemas</a>
                    <a href="#glossary">Glossário</a>
                </nav>

