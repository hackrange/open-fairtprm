
                <!-- Print-only: Cover Page -->
                <div class="print-cover">
                    <img class="cover-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
                    <div class="cover-rule"></div>
                    <h1>平台文档</h1>
                    <div class="cover-edition">Governance, Risk &amp; Compliance &bull; Third Party Risk Management</div>
                    <div class="cover-version">版本 2.6.1</div>
                    <div class="cover-rule-bottom"></div>
                    <div class="cover-meta">
                        <strong>日期：</strong> <?php echo date('F j, Y'); ?><br>
                        <strong>分类：</strong> 仅供内部使用<br>
                        <strong>编制单位：</strong> GRC 管理团队
                    </div>
                </div>

                <!-- Print-only: Introduction & Purpose -->
                <div class="print-intro">
                    <h2>简介</h2>

                    <h3>目的</h3>
                    <p>本文档为 Fair TPRM &amp; GRC 平台提供全面的使用说明，既是用户指南，也是所有参与第三方风险管理、治理、风险评估及合规运营人员的参考手册。</p>
                    <p>目标读者包括 GRC 分析师、合规官、审计员、IT 安全人员、采购团队及系统管理员。无论您是首次进行合规评估，还是正在管理持续审计项目，本指南均提供所需的分步操作说明。</p>

                    <h3>范围</h3>
                    <p>本文档涵盖以下平台模块和功能：</p>
                    <ul>
                        <li><strong>GRC 模块</strong> &mdash; 跨多个框架（SOC 2、ISO 27001、PCI DSS、NIST CSF、CMMC、HIPAA、CIS Controls、NIST 800-171）的统一合规评估、内部控制管理、证据收集、策略生命周期管理、审计管理、风险登记册、持续监控及成熟度评分</li>
                        <li><strong>TPRM 模块</strong> &mdash; 第三方供应商入驻、风险分级、安全评估、定量风险分析（FAIR）、外部安全评分、第四方风险追踪及影子 SaaS 发现</li>
                        <li><strong>管理门户</strong> &mdash; 系统配置、用户和群组管理、品牌设置、邮件设置、SSO/SAML 集成及 AI 平台配置</li>
                    </ul>

                    <h3>如何使用本指南</h3>
                    <p>本指南分为四个部分。<strong>第 1 部分（入门）</strong>涵盖平台导航、用户角色及首次登录。<strong>第 2 部分（GRC 模块）</strong>提供合规评估流程的详细演练，从创建首个评估开始，逐步介绍证据收集、评分及报告生成。<strong>第 3 部分（TPRM 模块）</strong>涵盖供应商风险管理。<strong>第 4 部分（管理门户）</strong>涵盖系统管理。</p>
                    <p>如果您是平台新用户，请先阅读<em>入门</em>部分，然后按照五步 GRC 快速入门指南操作。每个步骤均包含精确的逐步点击说明。</p>

                    <h3>文档约定</h3>
                    <p>本文档通篇使用以下约定：</p>
                    <ul>
                        <li><strong>粗体文本</strong>表示重要概念或强调内容</li>
                        <li><code>Code formatting</code>表示您输入的值或系统生成的引用</li>
                        <li>编号步骤列表表示需按顺序执行的操作流程</li>
                        <li>标注框提供提示、警告及重要背景信息</li>
                    </ul>
                </div>

                <!-- Print-only: Table of Contents (populated by JS) -->
                <div class="print-toc">
                    <h2>目录</h2>
                    <div id="tocBody"></div>
                </div>

                <!-- Main content body -->
                <div class="doc-body">

                <h1>平台文档</h1>
                <p class="doc-subtitle">Fair TPRM &amp; GRC Platform &mdash; 版本 2.6.1 &mdash; 最后更新：<?php echo date('F j, Y'); ?></p>

                <div style="margin-bottom:20px;">
                    <button class="btn-doc" id="printBtn">下载 PDF</button>
                </div>

<!-- old doc-toc removed — print-toc above now serves as the on-screen TOC -->


<!-- ================================================================
     GETTING STARTED
     ================================================================ -->
<div class="doc-section" id="overview">
    <h2>平台概述</h2>
    <p>本平台提供两个集成模块，用于管理您组织的安全态势：</p>
    <ul>
        <li><strong>TPRM（第三方风险管理）</strong> &mdash; 跟踪、评估和评分您的供应商和供货商，了解每个供应商对您组织带来的安全风险。</li>
        <li><strong>GRC（治理、风险与合规）</strong> &mdash; 管理合规框架（SOC 2、ISO 27001、PCI DSS、NIST CSF、CMMC、HIPAA、CIS Controls 等），通过统一问卷同时覆盖所有框架，跟踪内部控制，上传证据，管理策略，并运行审计。</li>
    </ul>
    <p>管理员还可访问<strong>管理门户</strong>，用于系统配置、用户管理、集成及维护。</p>

    <div class="callout callout-success">
        <strong>核心概念 &mdash; 一次评估，覆盖多个框架：</strong> GRC 模块使用<em>统一评估问卷</em>，涵盖 14 个安全领域共 146 个问题。您只需回答一次，平台将自动计算您对每个受支持框架（SOC 2、ISO 27001、PCI DSS 等）的合规百分比 &mdash; 无需重复工作。
    </div>
</div>

<div class="doc-section" id="navigation">
    <h2>侧边栏导航</h2>
    <p>左侧侧边栏是您的主要导航工具，按可折叠的模块和章节组织：</p>
    <ol class="steps">
        <li>侧边栏顶部显示您的公司徽标和品牌文字。</li>
        <li>下方有两个可折叠的模块标题：<span class="menu-label">TPRM Module</span> 和 <span class="menu-label">GRC Module</span>。点击任一标题可展开或折叠。浏览器会记住哪些模块处于展开状态。</li>
        <li>每个模块内部有可折叠的<strong>章节</strong>（例如"Compliance"、"Evidence &amp; Monitoring"、"Assessment &amp; Audit"）。点击章节标题可展开并查看其中的导航链接。</li>
        <li>侧边栏底部有实用链接：<span class="menu-label">Dashboard</span>、<span class="menu-label">Profile</span>、<span class="menu-label">Documentation</span>（本页）和 <span class="menu-label">Administration</span>（仅管理员）。</li>
    </ol>

    <h3>GRC 模块侧边栏结构</h3>
    <p>展开 <span class="menu-label">GRC Module</span> 后，您将看到以下章节：</p>
    <table class="doc-table">
        <tr><th>章节</th><th>内部页面</th><th>内容说明</th></tr>
        <tr><td><strong>Compliance</strong></td><td>GRC Dashboard、Frameworks、Internal Controls、Framework Crosswalk</td><td>合规态势概览、框架管理、控制库及跨框架映射</td></tr>
        <tr><td><strong>Evidence &amp; Monitoring</strong></td><td>Evidence Library、Continuous Monitors</td><td>上传和管理合规证据；配置自动化合规检查</td></tr>
        <tr><td><strong>Policy Management</strong></td><td>Policies</td><td>创建、版本控制、审批并发布组织策略</td></tr>
        <tr><td><strong>Assessment &amp; Audit</strong></td><td>CSF Maturity Score、Assessment Questionnaire、Task Inbox、Audits、Findings、Risk Register</td><td>统一评估问卷、成熟度仪表盘、审计及风险跟踪</td></tr>
    </table>
</div>

<div class="doc-section" id="roles">
    <h2>用户角色与权限</h2>
    <p>用户被分配到一个或多个<strong>ACL 群组</strong>，该群组决定了其可查看和执行的操作。管理员通过 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span> &rarr; <span class="btn-label">Groups</span> 按钮分配群组。</p>
    <table class="doc-table">
        <tr><th>群组</th><th>可执行操作</th></tr>
        <tr><td><strong>Administrator</strong></td><td>完全访问权限 &mdash; 所有模块、管理设置、用户管理及系统配置</td></tr>
        <tr><td><strong>Cyber TPRM</strong></td><td>TPRM 模块完全访问权限 &mdash; 创建/编辑/删除供应商、运行评估、FAIR 分析、评分</td></tr>
        <tr><td><strong>Cyber GRC</strong></td><td>GRC 模块完全访问权限 &mdash; 管理框架、运行评估、上传证据、管理策略、运行审计、管理风险</td></tr>
        <tr><td><strong>GRC Contributors</strong></td><td>有限 GRC 访问权限 &mdash; 完成分配的任务、提供证据、回答分配的评估问题</td></tr>
        <tr><td><strong>Auditor</strong></td><td>TPRM 和 GRC 模块的<strong>只读访问权限</strong> &mdash; 可查看所有内容、下载证据和生成报告，但无法创建、编辑或删除</td></tr>
        <tr><td><strong>Procurement</strong></td><td>创建和管理供应商入驻请求，上传供应商文件</td></tr>
        <tr><td><strong>Stakeholder</strong></td><td>查看自己的供应商请求并响应分配给他们的任务</td></tr>
    </table>

    <div class="callout callout-info">
        <strong>要在侧边栏中看到 GRC 模块：</strong>您必须属于 <strong>Administrator</strong>、<strong>Cyber GRC</strong> 或 <strong>Auditor</strong> 群组。如果您在侧边栏中看不到 GRC 模块，请联系管理员将您添加到上述群组之一。
    </div>
</div>

<div class="doc-section" id="first-login">
    <h2>首次登录</h2>
    <ol class="steps">
        <li>打开网络浏览器，导航到您的平台 URL（例如 <code>https://tprm.yourcompany.com</code>）。</li>
        <li>输入管理员提供的<span class="field-label">用户名</span>和<span class="field-label">密码</span>。</li>
        <li>如果您的账户已启用双因素身份验证（TOTP），请打开您的身份验证器应用（Google Authenticator、Microsoft Authenticator 等），并在提示时输入 6 位数字代码。</li>
        <li>您将进入<strong>仪表盘</strong>。顶部栏显示"欢迎，[您的姓名]"，并附有 Admin（如果您是管理员）、Profile 和 Logout 的链接。</li>
        <li>查看左侧侧边栏。如果您属于 <strong>Cyber GRC</strong> 或 <strong>Administrator</strong> 群组，侧边栏中将显示 <span class="menu-label">GRC Module</span>。点击它可展开 GRC 导航。</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/dashboard.png" alt="The platform dashboard after logging in" loading="lazy">
        <figcaption><strong>仪表盘。</strong>登录后即进入此页面。顶部栏（右上角）有 <strong>Admin</strong>、<strong>Profile</strong> 和 <strong>Logout</strong>。左侧侧边栏是您的主菜单。</figcaption>
    </figure>
</div>


<!-- ================================================================
     WHAT'S NEW IN 2.6.1
     ================================================================ -->
<div class="doc-section" id="whats-new">
    <h2>2.6.1 版本新功能</h2>
    <p>版本 2.6.1 新增了多项功能，重点关注<strong>供应商入驻、采购协作、多语言支持和影子 SaaS 发现</strong>。如果您使用过早期版本，以下是新增内容。每一项均链接至本指南后续的完整演练说明。</p>
    <table class="doc-table">
        <tr><th>新功能</th><th>功能说明</th><th>适用对象</th></tr>
        <tr><td><strong><a href="#language">语言设置</a></strong></td><td>支持 8 种语言使用平台。每位用户自行选择语言；管理员决定哪些语言可用。</td><td>全体用户</td></tr>
        <tr><td><strong><a href="#onboarding-workflow">采购入驻与供应商 ID</a></strong></td><td>供应商必须通过采购流程完成入驻并获得有效的供应商 ID（VID），才能提交进行网络安全审查。</td><td>Procurement、Stakeholders</td></tr>
        <tr><td><strong><a href="#ai-review">供应商 AI 审查</a></strong></td><td>为使用 AI 服务的供应商提供专属审查状态，以及"强制 AI 审查"操作。</td><td>Cyber TPRM、管理员</td></tr>
        <tr><td><strong><a href="#procurement-cyber-status">采购网络安全状态</a></strong></td><td>实时页面，显示正在审查中的供应商，以及网络安全团队与采购部门共享的持续更新历史记录，并提供每周电子邮件摘要。</td><td>Procurement、Cyber TPRM</td></tr>
        <tr><td><strong><a href="#shadow-saas-grip">Grip 影子 SaaS 集成</a></strong></td><td>自动发现整个组织使用的 SaaS 应用，并将其纳入影子 SaaS 列表。</td><td>管理员</td></tr>
        <tr><td><strong><a href="#shadow-saas-hero">Hero 影子 SaaS 集成</a></strong></td><td>替代影子 SaaS 提供商：从 HERO Security 发现供应商和安全问题，并将其纳入同一影子 SaaS 列表。Grip 和 Hero 互斥 &mdash; 只能使用其中之一。</td><td>管理员</td></tr>
        <tr><td><strong><a href="#zscaler">Zscaler 封锁</a></strong></td><td>一键直接在 Zscaler 中封锁未授权应用的网络域名。</td><td>管理员</td></tr>
        <tr><td><strong><a href="#admin-updates">应用内升级</a></strong></td><td>在管理门户内检查注册表中的新版本并进行升级。</td><td>管理员</td></tr>
        <tr><td><strong><a href="#question-types">电话和 VAT 问题类型</a></strong></td><td>新的评估/入驻字段类型：带国家代码和国旗选择器的电话号码（自动格式化），以及带二次输入确认并可对欧盟官方 VIES 服务进行实时验证的欧盟 VAT 号码。</td><td>全体用户</td></tr>
        <tr><td><strong><a href="#question-types">供应商数据与搜索改进</a></strong></td><td>在每个供应商记录上存储 VAT 号码（在供应商页面显示，并附有"添加 VAT"快捷方式），支持在快速搜索中按 VAT 号码查找供应商，以及更清晰的采购入驻评分横幅。</td><td>Procurement、Cyber TPRM</td></tr>
        <tr><td><strong><a href="#admin-backup">大型数据库备份</a></strong></td><td>备份和恢复功能现在支持数 GB 大小的数据库和超大记录，不会超时。</td><td>管理员</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>如何确认我当前的版本？</strong>管理员可前往 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span> 查看已安装的版本。本指南描述的是 <strong>v2.6.1</strong>。请参阅 <a href="#admin-updates">平台更新</a>。
    </div>
</div>

<div class="doc-section" id="language">
    <h2>更改语言 <span class="new-badge">2.6.1 新功能</span></h2>
    <p>平台界面支持 <strong>8 种语言</strong>显示。每位用户自行选择语言 &mdash; 更改仅影响<em>您自己</em>的界面，不影响其他人。您的选择在每次登录后都会被记住。</p>

    <h3>可用语言</h3>
    <table class="doc-table">
        <tr><th>语言</th><th>菜单中显示为</th></tr>
        <tr><td>英语</td><td>English</td></tr>
        <tr><td>西班牙语</td><td>Espa&ntilde;ol</td></tr>
        <tr><td>意大利语</td><td>Italiano</td></tr>
        <tr><td>乌克兰语</td><td>&#1059;&#1082;&#1088;&#1072;&#1111;&#1085;&#1089;&#1100;&#1082;&#1072;</td></tr>
        <tr><td>中文（简体）</td><td>&#20013;&#25991;&#65288;&#31616;&#20307;&#65289;</td></tr>
        <tr><td>印地语</td><td>&#2361;&#2367;&#2344;&#2381;&#2342;&#2368;</td></tr>
        <tr><td>法语</td><td>Fran&ccedil;ais</td></tr>
        <tr><td>葡萄牙语</td><td>Portugu&ecirc;s</td></tr>
    </table>
    <p class="callout callout-info" style="margin-top:0;"><strong>您的列表中仅显示管理员已开启的语言。</strong>英语始终可用，无法关闭。</p>

    <h3>分步更改语言</h3>
    <ol class="steps">
        <li>点击任意页面右上角的 <span class="menu-label">Profile</span>。</li>
        <li>在个人资料页面，向下滚动至 <span class="field-label">Language Preference</span> 卡片。</li>
        <li>点击 <span class="field-label">Language</span> 下拉菜单，选择您的语言。若要恢复管理员为所有人设置的语言，请选择 <strong>System default</strong>。</li>
        <li>点击 <span class="btn-label">Update Language</span>。页面重新加载后，菜单、按钮和标签将以您选择的语言显示。</li>
    </ol>
    <figure class="doc-figure narrow">
        <img src="app/docs/profile-language-card.png" alt="Language Preference card on the Profile page" loading="lazy">
        <figcaption><strong>Profile &rarr; Language Preference。</strong>选择一种语言，然后点击 <strong>Update Language</strong>。选择 <em>System default</em> 将移除您的个人语言偏好。</figcaption>
    </figure>

    <h3>管理员：选择可用语言</h3>
    <p>管理员可决定<strong>默认语言</strong>（用于全新用户和登录前的登录页面），以及允许所有人选择的语言。</p>
    <ol class="steps">
        <li>前往 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">General</span>。</li>
        <li>找到 <span class="field-label">Default Language</span> 下拉菜单，选择组织范围的默认语言。</li>
        <li>在 <span class="field-label">Enabled Languages</span> 下，勾选您希望开放的语言。（英语始终处于勾选状态，无法禁用。）</li>
        <li>点击 <span class="btn-label">Save Configuration</span>。</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-general-language.png" alt="Default Language and Enabled Languages settings in Admin General" loading="lazy">
        <figcaption><strong>Admin &rarr; General。</strong>设置 <strong>Default Language</strong> 并勾选用户可选的 <strong>Enabled Languages</strong>。</figcaption>
    </figure>

    <div class="callout callout-warning">
        <strong>注意事项：</strong>大部分界面（菜单、按钮、标签和表单字段）均已翻译。供应商评估<em>问题</em>在配置了 AI 提供商的情况下也可自动翻译（请参阅 <a href="#admin-ai">AI 集成</a>），但存储的答案值保持英文，以确保评分和报告在所有语言中保持一致。
    </div>
</div>


<!-- ================================================================
     PHONE & VAT QUESTION TYPES + VENDOR DATA IMPROVEMENTS
     ================================================================ -->
<div class="doc-section" id="question-types">
    <h2>电话和 VAT 问题类型 <span class="new-badge">2.6.1 新功能</span></h2>
    <p><strong>模板构建器</strong>（<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Assessment Templates</span>）新增两种问题类型，以简洁统一的格式采集联系方式和税务详情。这些类型可用于任何评估或入驻模板，与其他问题一样，可映射到供应商字段，使答案自动同步至供应商记录。</p>

    <h3>电话</h3>
    <p><strong>Phone</strong> 类型在号码输入框旁显示带国旗和区号的国家选择器。美国排在首位，其余国家按字母顺序排列。无论输入何种格式——<code>314-444-5544</code>、<code>(314)&nbsp;444-5544</code> 或 <code>3144445544</code>——号码均以统一的国际格式存储（例如，选择美国国旗并输入 <code>3144445544</code> 后存储为 <code>+13144445544</code>）。默认供应商入驻申请表已将主要联系人的电话号码字段更新为此类型，评估<strong>证明</strong>电话字段也同样使用该类型。</p>

    <h3>VAT（欧盟 VAT 编号）</h3>
    <p><strong>VAT</strong> 类型用于欧洲增值税编号。为防止输入错误，需<strong>输入两次</strong>，且两次输入必须匹配才能保存。编号以统一格式存储（大写，无空格或标点 &mdash; 例如 <code>DE123456789</code>）。</p>
    <ul>
        <li><strong>免费实时验证。</strong>输入完成后，平台将通过官方 <strong>EU VIES</strong> 服务（欧盟委员会增值税信息交换系统）验证该编号。VIES 免费使用，无需注册账户，并反映各成员国的实时注册信息。</li>
        <li><strong>仅作提示，不阻止保存。</strong>若 VIES 无法确认该编号，数据仍会保存 &mdash; 系统只会提示您仔细核对。若 VIES 暂时响应缓慢或某国注册机构暂时不可用，编号会被保存，并提示您稍后进行验证。</li>
        <li><strong>按需查看详情。</strong>当 VIES 确认编号后，旁边将出现信息图标（&#9432;）。点击后可展开面板，显示 VIES 返回的已注册公司名称和地址。</li>
    </ul>
    <div class="callout callout-info">
        <strong>将 VAT 映射到供应商记录。</strong>专用 <code>vat_number</code> 字段可用，因此映射到该字段的 VAT 问题答案将存储在供应商记录上。在模板构建器中选择 VAT 问题类型时，此映射会自动为您选定。
    </div>

    <h3>供应商页面上的 VAT</h3>
    <p>供应商的 VAT 编号显示在供应商入驻页面的<strong>供应商信息</strong>卡片中。如果尚无 VAT 记录，将显示 <strong>&ldquo;+ Add VAT&rdquo;</strong> 按钮，点击后直接进入编辑模式并聚焦于 VAT 字段。</p>

    <h3>按 VAT 编号查找供应商</h3>
    <p>平台右上角的<strong>快速搜索</strong>框现在也支持按 VAT 编号匹配，与供应商名称、域名和利益相关者并列。供应商自身名称、域名或 VAT 编号的直接匹配项始终优先显示。</p>

    <h2>采购入驻评分横幅 <span class="new-badge">2.6.1 新功能</span></h2>
    <p>当供应商的<strong>采购入驻</strong>状态设置为<strong>否</strong>时，现在将显示横幅，明确说明<em>在供应商完成采购入驻之前，自动供应商评分功能已禁用</em>。该横幅同时显示在供应商入驻页面和相应评估问题下方，并随答案变更即时更新。</p>

    <h2>提交评估：首先检查必填字段 <span class="new-badge">2.6.1 新功能</span></h2>
    <p>当供应商点击评估的<strong>提交</strong>按钮时，平台现在会在要求填写提交者证明信息<em>之前</em>先检查所有必填问题是否已回答。此前，缺少答案的情况只会在填写证明信息后才被报告，导致需要重新填写。</p>
</div>

<!-- ================================================================
     LARGE DATABASE BACKUPS
     ================================================================ -->
<div class="doc-section" id="admin-backup">
    <h2>大型数据库备份与恢复 <span class="new-badge">2.6.1 新功能</span></h2>
    <p>备份和恢复功能（<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Backup</span>）现在可以处理<strong>数 GB 大小的数据库</strong>和接近 <strong>1&nbsp;GB</strong> 的单条记录，而不会因超时或内存不足而中断操作。后台已相应提高了数据库数据包限制、网络超时、上传大小及请求时间限制，以适应超大数据。</p>
    <div class="callout callout-info">
        <strong>对于超大型数据库：</strong>多 GB 文件的备份或恢复可能需要一段时间 &mdash; 请保持页面打开直至操作完成。超大型数据集（数十 GB）最好通过服务器命令行进行恢复。
    </div>
</div>


<!-- ================================================================
     GRC MODULE - COMPREHENSIVE DOCUMENTATION
     ================================================================ -->
<div class="doc-section" id="grc-overview">
    <h2>GRC 模块：什么是治理、风险与合规？</h2>
    <p><strong>GRC</strong> 代表<strong>治理、风险与合规</strong>（Governance, Risk, and Compliance）。它是确保您的组织满足法规要求、遵循安全最佳实践、管理风险，并能向审计员和监管机构证明合规性的实践体系。</p>

    <p>GRC 模块帮助您：</p>
    <ul>
        <li><strong>评估安全成熟度</strong> &mdash; 使用单一统一问卷，同时映射到多个合规框架</li>
        <li><strong>跟踪合规性</strong> &mdash; 针对 SOC 2、ISO 27001、PCI DSS、NIST CSF、CMMC、HIPAA、CIS Controls 等框架</li>
        <li><strong>管理内部控制</strong> &mdash; 记录您的组织已实施的安全措施</li>
        <li><strong>收集和存储证据</strong> &mdash; 上传截图、配置导出文件、策略文档和证书以证明合规性</li>
        <li><strong>管理策略</strong> &mdash; 创建、版本控制、审批并发布组织安全策略</li>
        <li><strong>运行审计</strong> &mdash; 规划审计、记录发现、分配整改任务并跟踪关闭进度</li>
        <li><strong>跟踪风险</strong> &mdash; 维护包含可能性/影响评分和处置计划的风险登记册</li>
        <li><strong>持续监控</strong> &mdash; 设置按计划验证合规控制的自动检查</li>
    </ul>

    <div class="callout callout-warning">
        <strong>重要概念 &mdash; 统一问题：</strong>平台包含 <strong>146 个统一安全问题</strong>，组织为 <strong>14 个安全领域</strong>（治理、身份与访问管理、数据安全、网络安全等）。每个问题均预先映射到多个合规框架的具体要求。回答一次问题后，答案将自动应用于该问题映射的所有框架，无需分别针对 SOC 2、ISO 27001 和 PCI DSS 重复回答相同问题。
    </div>
</div>


<div class="doc-section" id="grc-getting-started">
    <h2>GRC 入门 &mdash; 快速入门指南</h2>
    <p>如果您是 GRC 模块的新用户，请按顺序执行以下步骤。完成后，您将获得一份涵盖所有框架评分的完整合规评估。</p>

    <div class="callout callout-info">
        <strong>前提条件：</strong><br>
        &bull; 您必须以 <strong>Administrator</strong> 或 <strong>Cyber GRC</strong> 群组的用户身份登录<br>
        &bull; 您必须能在左侧侧边栏看到 <span class="menu-label">GRC Module</span><br>
        &bull; 如果看不到，请联系管理员将您分配到 Cyber GRC 群组（Admin &rarr; Users &rarr; 点击您姓名旁的 Groups 按钮 &rarr; 勾选"Cyber GRC" &rarr; 保存）
    </div>

    <p>推荐的工作流程为：</p>
    <ol>
        <li><strong>创建评估</strong> &mdash; 定义合规审查的范围和目的</li>
        <li><strong>回答问题</strong> &mdash; 逐一完成 146 个统一问题，为每个问题评定成熟度等级</li>
        <li><strong>上传证据</strong> &mdash; 附上证明您答案的文档、截图和文件</li>
        <li><strong>查看评分</strong> &mdash; 在框架页面检查您的合规百分比</li>
        <li><strong>生成报告</strong> &mdash; 为审计员创建详细的按框架合规报告</li>
    </ol>
    <p>以下将详细说明每个步骤。</p>
</div>


<div class="doc-section" id="grc-step1">
    <h2>第 1 步：创建第一个评估</h2>
    <p><strong>评估</strong>是对您组织的合规审查，代表您回答安全问题、记录成熟度评级和收集证据的某一时间点评估。可将其理解为"合规快照"。</p>

    <h3>如何创建新评估</h3>
    <ol class="steps">
        <li>在左侧侧边栏中，点击 <span class="menu-label">GRC Module</span> 展开它。</li>
        <li>点击 <span class="menu-label">Assessment &amp; Audit</span> 章节展开它。</li>
        <li>点击 <span class="menu-label">Assessment Questionnaire</span>，打开主评估页面。</li>
        <li>在页面顶部，您将看到 <span class="btn-label">+ New Assessment</span> 按钮。点击它。</li>
        <li>将出现一个表单。填写以下字段：
            <ul>
                <li><span class="field-label">Title</span> &mdash; 为您的评估起一个描述性名称。示例：<code>2026 Annual Security Assessment - ACME Corp</code></li>
                <li><span class="field-label">Assessment Type</span> &mdash; 选择评估类型：
                    <ul>
                        <li><strong>Initial</strong> &mdash; 您的首次评估（建议新用户选择）</li>
                        <li><strong>Periodic</strong> &mdash; 定期重复评估（例如年度审查）</li>
                        <li><strong>Targeted</strong> &mdash; 针对特定领域的专项评估</li>
                        <li><strong>Pre-Audit</strong> &mdash; 正式审计前的准备评估</li>
                        <li><strong>Certification</strong> &mdash; 用于认证目的的评估（例如 SOC 2 Type II）</li>
                    </ul>
                </li>
                <li><span class="field-label">Scope</span> &mdash; 选择或描述组织范围，定义正在评估的组织部分（例如"所有 IT 系统"或"云基础设施"）。</li>
                <li><span class="field-label">Lead Auditor</span> &mdash; 选择主导本次评估的负责人。下拉列表仅显示 Administrator 或 Cyber GRC 群组中的用户。</li>
                <li><span class="field-label">Planned Start Date</span> &mdash; 计划开始评估的日期。</li>
                <li><span class="field-label">Planned End Date</span> &mdash; 目标完成日期。</li>
            </ul>
        </li>
        <li>点击 <span class="btn-label">Create Assessment</span>。</li>
        <li>您的新评估以 <span class="status-label">Draft</span>（草稿）状态创建，现在可以开始回答问题了。</li>
    </ol>

    <div class="example-box">
        <strong>示例：</strong>您正在进行组织的首次年度安全审查。<br><br>
        &bull; 标题：<code>2026 Annual Security Assessment</code><br>
        &bull; 类型：<code>Initial</code><br>
        &bull; 范围：<code>All Corporate IT Systems</code><br>
        &bull; 主审计员：<code>Jane Smith</code><br>
        &bull; 开始日期：<code>March 1, 2026</code><br>
        &bull; 结束日期：<code>April 30, 2026</code>
    </div>

    <h3>评估状态</h3>
    <table class="doc-table">
        <tr><th>状态</th><th>含义</th></tr>
        <tr><td><span class="status-label">Draft</span></td><td>评估已创建但尚未开始工作，可以回答问题。</td></tr>
        <tr><td><span class="status-label">In Progress</span></td><td>进行中的评估 &mdash; 团队成员正在回答问题和上传证据。</td></tr>
        <tr><td><span class="status-label">Under Review</span></td><td>所有问题已回答 &mdash; 主审计员或验证人正在审查回复。</td></tr>
        <tr><td><span class="status-label">Completed</span></td><td>评估已完成并最终确认，回复已锁定。</td></tr>
        <tr><td><span class="status-label">Archived</span></td><td>已存档的历史评估，不再活跃。</td></tr>
    </table>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment.png" alt="Assessment list on the Assessment Questionnaire page" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Assessment Questionnaire。</strong>所有评估均以参考编号、标题、类型、状态、主审计员、当前 CSF 评分和合规百分比及计划日期列出。使用 <span class="btn-label">+ New Assessment</span> 开始新评估，或使用 <span class="btn-label">Open</span> 继续填写现有评估。顶部的状态选项卡可过滤列表。</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step2">
    <h2>第 2 步：回答评估问题</h2>
    <p>创建评估后，您需要回答 146 个统一安全问题，每个问题属于 14 个安全领域之一。</p>

    <h3>14 个安全领域</h3>
    <table class="doc-table">
        <tr><th>代码</th><th>领域名称</th><th>问题数</th><th>涵盖内容</th></tr>
        <tr><td><code>GOV</code></td><td>Governance &amp; Leadership</td><td>12</td><td>安全项目领导力、战略、预算、董事会报告</td></tr>
        <tr><td><code>IAM</code></td><td>Identity &amp; Access Management</td><td>14</td><td>用户账户、身份验证、访问控制、特权访问</td></tr>
        <tr><td><code>DSP</code></td><td>Data Security &amp; Privacy</td><td>12</td><td>数据分类、加密、隐私、数据丢失防护</td></tr>
        <tr><td><code>EPS</code></td><td>Endpoint &amp; Platform Security</td><td>10</td><td>笔记本电脑、服务器、移动设备、补丁管理、EDR</td></tr>
        <tr><td><code>NET</code></td><td>Network Security</td><td>11</td><td>防火墙、分段、VPN、DNS 安全、Wi-Fi</td></tr>
        <tr><td><code>APS</code></td><td>Application Security</td><td>10</td><td>安全开发、代码审查、API 安全、WAF</td></tr>
        <tr><td><code>OPS</code></td><td>Security Operations</td><td>12</td><td>SIEM、日志记录、监控、漏洞扫描、SOC</td></tr>
        <tr><td><code>INC</code></td><td>Incident Management</td><td>10</td><td>事件响应计划、桌面演练、违规通知</td></tr>
        <tr><td><code>SCM</code></td><td>Supply Chain &amp; Third Party</td><td>10</td><td>供应商管理、供应链风险、合同</td></tr>
        <tr><td><code>PHY</code></td><td>Physical &amp; Environmental</td><td>8</td><td>数据中心、门禁卡、闭路电视、环境控制</td></tr>
        <tr><td><code>HRS</code></td><td>Human Resources Security</td><td>10</td><td>背景调查、安全培训、离职程序</td></tr>
        <tr><td><code>BCP</code></td><td>Business Continuity</td><td>10</td><td>备份、灾难恢复、BCP 测试、RTO/RPO</td></tr>
        <tr><td><code>CRY</code></td><td>Cryptography &amp; Key Management</td><td>8</td><td>加密标准、密钥轮换、证书管理</td></tr>
        <tr><td><code>CMP</code></td><td>Compliance &amp; Assurance</td><td>9</td><td>法规合规、内部审计、外部审计准备</td></tr>
    </table>

    <h3>如何回答问题</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Assessment Questionnaire</span>。</li>
        <li>如果您有多个评估，请从页面顶部的下拉菜单中选择正确的评估。</li>
        <li>您将看到列出的 14 个安全领域。点击领域名称（例如 <strong>GOV - Governance &amp; Leadership</strong>）可展开并查看其问题。</li>
        <li>对于每个问题，您需要提供两项信息：
            <ul>
                <li><span class="field-label">Maturity Rating</span>（1-4）&mdash; 您的组织对此控制措施的实施成熟度如何？
                    <ul>
                        <li><strong>1 &mdash; Initial/Ad Hoc：</strong>没有正式流程，执行不一致或完全未执行。</li>
                        <li><strong>2 &mdash; Developing：</strong>存在一些流程但未能持续遵循，部分有文档记录。</li>
                        <li><strong>3 &mdash; Defined：</strong>已建立正式的文档化流程并持续遵循。</li>
                        <li><strong>4 &mdash; Managed/Optimized：</strong>流程经过衡量、监控，并持续改进。</li>
                    </ul>
                </li>
                <li><span class="field-label">Conformity Status</span> &mdash; 该问题的合规状态：
                    <ul>
                        <li><strong>Conforming</strong> &mdash; 已完全实施，满足要求</li>
                        <li><strong>Partial</strong> &mdash; 部分实施，仍存在差距</li>
                        <li><strong>Non-Conforming</strong> &mdash; 未实施或不满足要求</li>
                        <li><strong>Not Applicable</strong> &mdash; 此问题不适用于您的组织</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li>可选：添加<span class="field-label">备注</span>来解释您的答案。强烈建议这样做 &mdash; 审计员会希望看到您的理由说明。</li>
        <li>您的回复会在填写过程中<strong>自动保存</strong>，无需点击保存按钮。</li>
        <li>继续回答全部 14 个领域的问题。您无需在一次会话中完成所有内容 &mdash; 随时可以回来继续。</li>
    </ol>

    <div class="callout callout-info">
        <strong>提示 &mdash; 成熟度决定合规状态：</strong>设置成熟度评级后，系统可自动推导合规状态：成熟度 3-4 = Conforming，成熟度 2 = Partial，成熟度 1 = Non-Conforming。如有需要可手动覆盖。
    </div>

    <div class="callout callout-warning">
        <strong>重要提示：</strong>您回答的每个问题都会映射到多个框架的要求。例如，回答一个关于"多因素身份验证"（IAM 领域）的问题，会同时更新您在 SOC 2、ISO 27001、PCI DSS、NIST CSF 和 CMMC 的合规评分，永远不需要重复回答相同的概念。
    </div>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment-questions.png" alt="Answering questions in the assessment questionnaire" loading="lazy">
        <figcaption><strong>填写问卷。</strong>页眉实时跟踪<em>进度</em>、<em>CSF 成熟度</em>和<em>合规性</em>。领域选项卡（GOV、IAM、DSP、&hellip;）各显示该领域的当前评分；点击可跳转到其问题。对于每个问题，您需设置<strong>成熟度</strong>评级（1&ndash;4 或 N/A）和<strong>合规</strong>状态 &mdash; 答案自动保存。使用 <span class="btn-label">Show Unanswered Questions</span> 查找剩余未回答的问题。</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step3">
    <h2>第 3 步：上传证据</h2>
    <p>证据证明您的答案准确无误。审计员希望看到每项合规声明的证据，证据可包括截图、配置导出文件、策略文档、审计日志、证书等。</p>

    <h3>如何在评估过程中上传证据</h3>
    <ol class="steps">
        <li>在 <span class="menu-label">Assessment Questionnaire</span> 中回答问题时，查找问题回复区域下方的<strong>证据</strong>部分。</li>
        <li>点击 <span class="btn-label">Upload Evidence</span> 或附件图标。</li>
        <li>从您的计算机中选择文件。支持的类型包括 PDF、图片（PNG、JPG）、Word 文档、Excel 表格和文本文件。</li>
        <li>为证据提供描述性的 <span class="field-label">Title</span>（例如"MFA Configuration Screenshot - Okta Admin Console"）。</li>
        <li>证据自动关联到当前评估问题。</li>
        <li>每个问题可上传多个证据文件。</li>
    </ol>

    <div class="callout callout-success">
        <strong>安全性：</strong>所有上传的证据文件在存储到数据库之前均经过加密（AES-256-CBC）。下载证据时，文件会即时解密。这确保了敏感合规文件在静态存储时受到保护。
    </div>

    <h3>证据库</h3>
    <p>您也可以通过 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Evidence Library</span> 单独管理证据。此页面显示所有评估和控制措施的全部证据，可按类型、状态和到期日期筛选。</p>
</div>


<div class="doc-section" id="grc-step4">
    <h2>第 4 步：查看合规评分</h2>
    <p>随着您回答问题，平台会实时计算每个框架的合规百分比。</p>

    <h3>在框架页面查看评分</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>。</li>
        <li>在页面顶部，您将看到 <span class="field-label">Assessment</span> 下拉菜单。选择您要查看评分的评估，默认选择最近的评估。</li>
        <li>下拉菜单下方将显示框架卡片 &mdash; 每个已映射问题的合规框架对应一张卡片。每张卡片显示：
            <ul>
                <li>显示总体合规百分比的<strong>环形图</strong>（例如 75%）</li>
                <li><strong>框架代码和名称</strong>（例如"SOC2 &mdash; SOC 2 Type II"）</li>
                <li><strong>平均成熟度</strong>评分（如存在成熟度数据，显示为例如"3.50 / 4.00"）</li>
                <li>指标计数：<strong>Conforming</strong>、<strong>Partial</strong>、<strong>Non-Conforming</strong> 和 <strong>Total Mapped</strong></li>
            </ul>
        </li>
        <li>点击任意框架卡片可打开该框架的详细<strong>合规报告</strong>。</li>
    </ol>

    <h3>合规百分比计算方法</h3>
    <p>合规百分比的计算公式为：</p>
    <div class="example-box">
        <strong>公式：</strong><code>（Conforming + Partial &times; 0.5）&divide; 适用要求数 &times; 100</code><br><br>
        &bull; <strong>Conforming</strong> 要求计为 100% 完成<br>
        &bull; <strong>Partial</strong> 要求计为 50% 完成<br>
        &bull; <strong>Not Applicable</strong> 要求从计算中排除<br>
        &bull; <strong>Non-Conforming</strong> 和 <strong>Not Assessed</strong> 要求计为 0%
    </div>

    <h3>当前支持的框架</h3>
    <table class="doc-table">
        <tr><th>框架</th><th>版本</th><th>映射问题数</th></tr>
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
    <h2>第 5 步：生成框架合规报告</h2>
    <p>回答问题后，您可以为任意框架生成详细的合规报告，该报告适合与审计员、监管机构或管理层分享。</p>

    <h3>如何生成报告</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>。</li>
        <li>从顶部的 <span class="field-label">Assessment</span> 下拉菜单中选择您的评估。</li>
        <li>点击您要生成报告的框架卡片（例如"SOC2 &mdash; SOC 2 Type II"）。</li>
        <li><strong>框架合规报告</strong>页面打开后显示：
            <ul>
                <li><strong>报告标题</strong> &mdash; 框架名称、评估标题、类型、状态、范围、主审计员、日期及总体合规百分比</li>
                <li><strong>汇总统计</strong> &mdash; 可点击的卡片，显示总要求数、Conforming、Partial、Non-Conforming、Not Assessed 和 N/A 计数</li>
                <li><strong>要求卡片</strong> &mdash; 每个框架要求对应一张卡片，显示要求参考编号、标题、状态标签，以及所有映射问题及其回复</li>
            </ul>
        </li>
        <li>要<strong>按状态筛选要求</strong>，点击顶部任意汇总统计卡片。例如，点击 <strong>Non-Conforming</strong> 仅显示不合规要求。再次点击（或点击"Total Requirements"）可显示全部。</li>
        <li>要<strong>打印报告</strong>，点击顶部的 <span class="btn-label">Print Report</span> 按钮，浏览器打印对话框将打开。您可以打印为纸质文件，或选择"另存为 PDF"创建 PDF 文件。</li>
    </ol>

    <h3>每张要求卡片显示的内容</h3>
    <p>报告中每项要求将显示：</p>
    <ul>
        <li><strong>要求参考编号</strong> &mdash; 官方参考编号（例如 SOC 2 的"CC6.1"）</li>
        <li><strong>要求标题</strong> &mdash; 要求的具体内容</li>
        <li><strong>状态标签</strong> &mdash; 颜色标识：绿色（Conforming）、琥珀色（Partial）、红色（Non-Conforming）、灰色（Not Assessed / N/A）</li>
        <li><strong>映射问题</strong> &mdash; 映射到此要求的每个问题，显示：
            <ul>
                <li>问题参考编号和文本</li>
                <li>成熟度评级（1-4），附带可视化进度条</li>
                <li>合规状态</li>
                <li>验证状态（Pending、Validated、Rejected、Needs Review）</li>
                <li>评估员姓名和日期</li>
                <li>映射强度（Exact、Strong、Partial、Related）</li>
                <li>评估员备注</li>
                <li>验证备注</li>
                <li>证据附件（附下载链接）</li>
            </ul>
        </li>
    </ul>
</div>


<div class="doc-section" id="grc-fairscore">
    <h2>CSF 成熟度评分仪表盘</h2>
    <p><strong>CSF Maturity Score</strong> 页面提供了一个可视化仪表盘，显示您的组织在所有 14 个安全领域的成熟度，与 NIST Cybersecurity Framework 对齐。</p>

    <h3>如何访问</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">CSF Maturity Score</span>。</li>
        <li>如果您有多个评估，请从下拉菜单中选择所需的评估。</li>
        <li>页面显示：
            <ul>
                <li><strong>总体 FAIR 评分</strong> &mdash; 所有领域的加权平均成熟度评分</li>
                <li><strong>雷达图</strong> &mdash; 以蜘蛛/雷达图形式展示所有 14 个领域的评分</li>
                <li><strong>领域评分卡片</strong> &mdash; 每个领域的独立卡片，显示平均成熟度、已回答问题数及合规状态分布</li>
                <li><strong>框架合规进度条</strong> &mdash; 水平进度条，显示每个框架的合规百分比</li>
                <li><strong>差距分析摘要</strong> &mdash; 评分低于目标的领域</li>
            </ul>
        </li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grc-fairscore.png" alt="CSF Maturity Score dashboard with radar chart" loading="lazy">
        <figcaption><strong>GRC Module &rarr; CSF Maturity Score。</strong>四个标题数据块——<em>CSF Maturity Score</em>（1&ndash;4 分制）、<em>Compliance Rate</em>、<em>Questions Answered</em> 和 <em>Gaps Found</em>——一目了然地总结您的安全态势。<strong>安全领域成熟度雷达图</strong>展示所有 14 个领域，右侧列表给出每个领域的精确平均分。可从顶部下拉菜单选择所需评估。</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-gaps">
    <h2>差距分析</h2>
    <p><strong>差距分析</strong>页面汇总了评估过程中发现的每一个弱项——每个被标记为 <strong>Non-Conforming</strong> 或 <strong>Partial</strong> 的问题——形成一份按优先级排序的工作清单，回答"我们在哪些方面存在不足，每个不足影响哪些内容？"</p>

    <h3>如何访问</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Gaps</span>。</li>
        <li>从 <span class="field-label">Assessment</span> 下拉菜单中选择要分析的评估。</li>
    </ol>

    <h3>页面显示内容</h3>
    <p>顶部四个汇总数据块分别统计您的<strong>总差距数</strong>、<strong>Non-Conforming</strong>、<strong>Partial</strong> 和<strong>已关联风险</strong>的差距数。下方每个差距以行的形式列出，包含：</p>
    <ul>
        <li><strong>严重程度</strong> &mdash; 标签：<em>Non-Conforming</em>（红色）或 <em>Partial</em>（琥珀色）。</li>
        <li><strong>领域</strong>和<strong>参考编号</strong> &mdash; 安全领域和具体问题参考编号（例如 <code>GOV-08</code>）。</li>
        <li><strong>发现</strong> &mdash; 描述缺失内容的问题文本。</li>
        <li><strong>框架影响</strong> &mdash; 此差距影响的所有框架要求的标签，让您一眼看出修复单个问题是否同时改善 SOC 2、ISO 27001、PCI DSS 等多个框架的合规性。</li>
        <li><strong>风险</strong> &mdash; 是否已为此差距记录风险，以及打开完整详情的 <span class="btn-label">View</span> 操作。</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/grc-gaps.png" alt="Gap Analysis page listing non-conforming and partial findings" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Gaps。</strong>每个不合规或部分合规的回复都成为一个差距。<strong>框架影响</strong>列显示该差距触及每个框架中的哪些要求——关闭一个差距可同时提升多个框架的合规性。</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-frameworks">
    <h2>框架页面</h2>
    <p><strong>Frameworks</strong> 页面是查看所有受支持框架合规状态的中心枢纽，显示基于评估的合规数据。</p>

    <h3>如何使用</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span>。</li>
        <li>从 <span class="field-label">Assessment</span> 下拉菜单中选择一个评估，页面默认显示最近的评估。</li>
        <li>页面以网格形式展示框架卡片，仅显示已映射问题的框架。每张卡片显示合规百分比、成熟度评分和指标计数。</li>
        <li>点击框架卡片可打开详细的合规报告。</li>
    </ol>

    <figure class="doc-figure">
        <img src="app/docs/grc-frameworks.png" alt="Compliance Frameworks page with per-framework compliance cards" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Frameworks。</strong>顶部数据块统计您的框架数、平均准备度、总要求数以及<em>需要关注</em>的数量。每张卡片显示框架的合规环形图、平均成熟度，以及 Conforming / Partial / Non-Conforming / Total-Mapped 分布。点击任意卡片可打开该框架的完整合规报告。</figcaption>
    </figure>

    <h3>框架要求树</h3>
    <p>如果您在<em>未选择</em>评估的情况下导航到此页面（或从其他地方点击框架链接），将看到<strong>要求树</strong>视图。该视图显示框架中所有要求的层级结构，以及映射的控制措施和实施状态。管理员和 Cyber GRC 用户可在此添加、编辑和删除自定义要求。</p>
</div>


<div class="doc-section" id="grc-controls">
    <h2>内部控制措施</h2>
    <p><strong>内部控制措施</strong>是您的组织已实施的具体安全措施。示例："所有系统启用多因素身份验证"、"每日加密备份"、"年度渗透测试"。</p>

    <h3>如何创建控制措施</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Internal Controls</span>。</li>
        <li>点击 <span class="btn-label">+ New Control</span>。</li>
        <li>填写以下字段：
            <ul>
                <li><span class="field-label">Control Title</span> &mdash; 简短名称（例如"MFA for all user accounts"）</li>
                <li><span class="field-label">Description</span> &mdash; 该控制措施的详细说明</li>
                <li><span class="field-label">Control Type</span> &mdash; Preventive（预防）、Detective（检测）、Corrective（纠正）或 Directive（指导）</li>
                <li><span class="field-label">Category</span> &mdash; Technical（技术）、Administrative（管理）或 Physical（物理）</li>
                <li><span class="field-label">Implementation Status</span> &mdash; Planned（计划中）、In Progress（进行中）、Implemented（已实施）或 Not Applicable（不适用）</li>
                <li><span class="field-label">Effectiveness</span> &mdash; Not Tested（未测试）、Ineffective（无效）、Partially Effective（部分有效）或 Effective（有效）</li>
                <li><span class="field-label">Risk Level</span> &mdash; Low（低）、Medium（中）、High（高）或 Critical（关键）</li>
                <li><span class="field-label">Owner</span> &mdash; 负责人（仅限 Administrator 和 Cyber GRC 群组成员）</li>
                <li><span class="field-label">Test Frequency</span> &mdash; 该控制措施的测试频率（每日、每周、每月等）</li>
            </ul>
        </li>
        <li>在<strong>框架映射</strong>下，选择此控制措施满足的框架要求。您可以将一个控制措施映射到多个框架的要求。</li>
        <li>点击 <span class="btn-label">Save</span>。</li>
    </ol>

    <div class="callout callout-success">
        <strong>核心优势 &mdash; 跨框架映射：</strong>单个控制措施（如"MFA"）可同时满足 SOC 2（CC6.1）、ISO 27001（A.8.5）、PCI DSS（8.4.2）和 NIST CSF（PR.AC-7）的要求。映射一次即可覆盖所有框架。
    </div>
</div>


<div class="doc-section" id="grc-crosswalk">
    <h2>框架交叉对照</h2>
    <p><strong>框架交叉对照</strong>显示符合某一框架的要求如何自动为另一框架提供覆盖。例如，如果您已符合 SOC 2，那么您已覆盖 ISO 27001 的多少内容？</p>

    <h3>如何使用</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Framework Crosswalk</span>。</li>
        <li>选择 <span class="field-label">Source Framework</span>（您已完成的框架，例如"SOC 2"）。</li>
        <li>选择 <span class="field-label">Target Framework</span>（您要对比的框架，例如"ISO 27001"）。</li>
        <li>交叉对照表显示哪些目标要求已被您的源控制措施覆盖，哪些存在差距。</li>
    </ol>
</div>


<div class="doc-section" id="grc-evidence">
    <h2>证据库</h2>
    <p><strong>证据库</strong>是组织所有合规证据的集中存储库。</p>

    <h3>如何上传证据</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Evidence Library</span>。</li>
        <li>点击 <span class="btn-label">+ Upload Evidence</span>。</li>
        <li>填写：<span class="field-label">Title</span>、<span class="field-label">Evidence Type</span>（截图、文档、证书、配置、报告等）、<span class="field-label">Description</span>，以及可选的 <span class="field-label">Expiry Date</span>。</li>
        <li>选择要上传的文件。</li>
        <li>点击 <span class="btn-label">Upload</span>，文件将加密后安全存储。</li>
        <li>然后您可以将此证据关联到特定控制措施或评估回复。</li>
    </ol>

    <h3>证据状态</h3>
    <table class="doc-table">
        <tr><th>状态</th><th>含义</th></tr>
        <tr><td><strong>Current</strong></td><td>有效的当前证据</td></tr>
        <tr><td><strong>Expired</strong></td><td>已超过有效期 &mdash; 需要更新</td></tr>
        <tr><td><strong>Superseded</strong></td><td>已被更新证据替代</td></tr>
        <tr><td><strong>Draft</strong></td><td>已上传但尚未审查或最终确认</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-policies">
    <h2>策略管理</h2>
    <p><strong>Policies</strong>（策略）页面提供完整的策略生命周期管理——从草稿、审批、发布到定期审查。</p>

    <h3>如何创建策略</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Policy Management</span> &rarr; <span class="menu-label">Policies</span>。</li>
        <li>点击 <span class="btn-label">+ New Policy</span>。</li>
        <li>填写：<span class="field-label">Title</span>、<span class="field-label">Category</span>（Security、Privacy、Compliance、Operational、HR、IT 等）、<span class="field-label">Review Frequency</span>（策略审查频率）。</li>
        <li>使用富文本编辑器撰写策略内容。</li>
        <li>点击 <span class="btn-label">Save</span>，策略以 <span class="status-label">Draft</span> 状态创建。</li>
        <li>准备就绪后，依次提交<strong>审查</strong> &rarr; <strong>审批</strong> &rarr; <strong>发布</strong>。</li>
    </ol>

    <h3>策略生命周期</h3>
    <p><span class="status-label">Draft</span> &rarr; <span class="status-label">Review</span> &rarr; <span class="status-label">Approved</span> &rarr; <span class="status-label">Published</span> &rarr;（定期审查或 <span class="status-label">Retired</span>）</p>
</div>


<div class="doc-section" id="grc-audits">
    <h2>审计与发现</h2>
    <p><strong>Audits</strong>（审计）页面管理完整的审计生命周期——从规划、现场工作、发现、整改到关闭。</p>

    <h3>如何创建审计</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Audits</span>。</li>
        <li>点击 <span class="btn-label">+ New Audit</span>。</li>
        <li>填写：<span class="field-label">Title</span>、<span class="field-label">Audit Type</span>（Internal、External、Certification、Surveillance、Readiness）、<span class="field-label">Framework</span>、<span class="field-label">Lead Auditor</span>、<span class="field-label">Planned Start/End Dates</span>。</li>
        <li>点击 <span class="btn-label">Create</span>。</li>
    </ol>

    <h3>记录发现</h3>
    <ol class="steps">
        <li>打开审计并点击 <span class="btn-label">+ Add Finding</span>。</li>
        <li>填写：<span class="field-label">Title</span>、<span class="field-label">Severity</span>（Informational、Low、Medium、High、Critical）、<span class="field-label">Finding Type</span>（Nonconformity、Observation、Opportunity、Strength）和 <span class="field-label">Description</span>。</li>
        <li>将发现映射到特定的框架要求或控制措施。</li>
        <li>将整改任务分配给团队成员并设置截止日期。</li>
        <li>跟踪整改进度直至达到<strong>已验证关闭</strong>状态。</li>
    </ol>

    <h3>审计状态</h3>
    <table class="doc-table">
        <tr><th>状态</th><th>含义</th></tr>
        <tr><td><strong>Planning</strong></td><td>定义范围、目标和计划</td></tr>
        <tr><td><strong>Fieldwork</strong></td><td>主动测试、证据审查和访谈</td></tr>
        <tr><td><strong>Reporting</strong></td><td>起草审计报告和记录发现</td></tr>
        <tr><td><strong>Remediation</strong></td><td>发现已报告；团队正在修复问题</td></tr>
        <tr><td><strong>Closed</strong></td><td>所有发现已解决，审计完成</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-risks">
    <h2>风险登记册</h2>
    <p><strong>风险登记册</strong>跟踪组织风险，包含可能性/影响评分、处置计划以及与控制措施的关联。</p>

    <h3>如何添加风险</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Risk Register</span>。</li>
        <li>点击 <span class="btn-label">+ New Risk</span>。</li>
        <li>填写：<span class="field-label">Title</span>、<span class="field-label">Description</span>、<span class="field-label">Category</span>（Strategic、Operational、Financial、Compliance、Reputational、Technology、Third Party）。</li>
        <li>设置 <span class="field-label">Likelihood</span>（Rare、Unlikely、Possible、Likely、Almost Certain）和 <span class="field-label">Impact</span>（Insignificant、Minor、Moderate、Major、Catastrophic）。</li>
        <li>系统计算<strong>固有风险评分</strong>（可能性 &times; 影响，1-25 分制）。</li>
        <li>选择 <span class="field-label">Treatment Strategy</span>（处置策略）：Accept（接受）、Mitigate（缓解）、Transfer（转移）或 Avoid（规避）。</li>
        <li>关联相关内部控制措施，展示风险如何被缓解。系统将在控制措施生效后计算<strong>剩余风险评分</strong>。</li>
    </ol>
</div>


<div class="doc-section" id="grc-monitors">
    <h2>持续监控</h2>
    <p><strong>持续监控</strong>是按计划（每小时、每天、每周或每月）自动验证安全控制措施的自动化检查。</p>

    <h3>如何创建监控</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Continuous Monitors</span>。</li>
        <li>点击 <span class="btn-label">+ New Monitor</span>。</li>
        <li>填写：<span class="field-label">Title</span>、<span class="field-label">Check Type</span>、<span class="field-label">Frequency</span>（Hourly、Daily、Weekly、Monthly）以及 <span class="field-label">Collector Configuration</span>（检查的 JSON 配置）。</li>
        <li>将监控关联到内部控制措施。</li>
        <li>启用监控，它将按配置的计划自动运行。</li>
        <li>在监控详情页面查看结果（Pass、Fail、Error、Warning）和执行历史。</li>
    </ol>
</div>


<div class="doc-section" id="grc-tasks">
    <h2>任务收件箱</h2>
    <p><strong>任务收件箱</strong>显示所有评估中分配给您的全部 GRC 任务。任务在评估过程中创建，用于委派证据收集、整改、审查或文档编制等工作。</p>

    <h3>如何使用</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Task Inbox</span>。</li>
        <li>您将看到分配给您的任务列表。每个任务显示：标题、类型（Evidence Request、Remediation、Review、Documentation、Implementation）、优先级、截止日期和状态。</li>
        <li>点击任务可查看详情并更新其状态。</li>
        <li>开始处理时将任务标记为 <span class="status-label">In Progress</span>，完成后标记为 <span class="status-label">Completed</span>。</li>
    </ol>
</div>


<div class="doc-section" id="grc-dashboard">
    <h2>GRC 仪表盘</h2>
    <p><strong>GRC 仪表盘</strong>是您的合规指挥中心 &mdash; 单页面展示您整体 GRC 态势的概览。</p>

    <h3>仪表盘显示内容</h3>
    <ul>
        <li><strong>框架合规热力图</strong> &mdash; 每个框架合规百分比的颜色编码显示</li>
        <li><strong>控制措施实施进度</strong> &mdash; 已实施与计划实施的控制措施数量对比</li>
        <li><strong>证据新鲜度</strong> &mdash; 当前有效、即将到期或已到期的证据数量</li>
        <li><strong>未解决发现</strong> &mdash; 未解决审计发现的数量及严重程度分布</li>
        <li><strong>策略审查状态</strong> &mdash; 需要审查的策略</li>
        <li><strong>监控健康状态</strong> &mdash; 持续监控的通过/失败状态</li>
        <li><strong>风险登记册摘要</strong> &mdash; 按严重程度分类的未关闭风险</li>
    </ul>

    <h3>如何访问</h3>
    <p>导航至 <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">GRC Dashboard</span>。</p>
</div>


<!-- ================================================================
     TPRM MODULE
     ================================================================ -->
<div class="doc-section" id="tprm-overview">
    <h2>TPRM 模块：什么是第三方风险管理？</h2>
    <p>每家公司都依赖外部供应商——云服务提供商、薪资公司、营销平台、IT 顾问。每个供应商都可能访问您的数据或系统。<strong>TPRM</strong> 帮助您回答："每个供应商的风险有多大，他们是否在保护我们的数据？"</p>
    <ul>
        <li>在一个地方添加和跟踪所有供应商</li>
        <li>分配风险等级（Tier 1 = 最高风险，Tier 3 = 最低风险）</li>
        <li>向供应商发送安全问卷（评估）</li>
        <li>使用外部安全评级服务自动为供应商评分</li>
        <li>执行定量风险分析（FAIR）以估算潜在财务损失</li>
        <li>跟踪第四方风险（您的供应商的供应商）</li>
        <li>发现未受管理的 SaaS 应用（影子 SaaS）</li>
    </ul>
</div>

<div class="doc-section" id="tprm-add-vendor">
    <h2>添加新供应商</h2>
    <ol class="steps">
        <li>在左侧侧边栏中，展开 <span class="menu-label">TPRM Module</span>，然后展开 <span class="menu-label">Stakeholders</span> 部分。</li>
        <li>点击 <span class="menu-label">New Request</span>，打开供应商入驻表单。</li>
        <li>填写必填字段：
            <ul>
                <li><span class="field-label">Vendor Name</span> &mdash; 公司法定名称（例如"Acme Cloud Services"）</li>
                <li><span class="field-label">Vendor Domain</span> &mdash; 不含 https:// 的网站域名（例如"acmecloud.com"），安全评分引擎将用于扫描该供应商。</li>
            </ul>
        </li>
        <li>填写推荐的可选字段：
            <ul>
                <li><span class="field-label">Vendor Type</span> &mdash; Technology、Professional Services、Financial Services、HR/Benefits 等</li>
                <li><span class="field-label">Vendor Tier</span> &mdash; 1（关键）、2（重要）或 3（标准）</li>
                <li><span class="field-label">Primary Contact Name</span>、<span class="field-label">Email</span>、<span class="field-label">Phone</span></li>
                <li><span class="field-label">PII Record Count</span> &mdash; 该供应商访问的个人记录数量</li>
                <li><span class="field-label">SPII Record Count</span> &mdash; 敏感个人记录（社会安全号码、健康数据）数量</li>
            </ul>
        </li>
        <li>点击 <span class="btn-label">Save</span>，供应商以<strong>草稿</strong>状态创建。</li>
    </ol>

    <div class="callout callout-info">
        <strong>供应商等级说明：</strong><br>
        &bull; <strong>Tier 1（关键）</strong> &mdash; 可访问敏感数据或关键系统的供应商，需要完整评估。<br>
        &bull; <strong>Tier 2（重要）</strong> &mdash; 具有中等访问权限的供应商，需要标准评估。<br>
        &bull; <strong>Tier 3（标准）</strong> &mdash; 低风险供应商，可能只需要基本审查。
    </div>
</div>

<div class="doc-section" id="tprm-lifecycle">
    <h2>供应商生命周期</h2>
    <p>供应商按照定义的生命周期流转：</p>
    <p><span class="status-label">Draft</span> &rarr; <span class="status-label">Pending Review</span> &rarr; <span class="status-label">In Review</span> &rarr; <span class="status-label">Approved</span>（或 <span class="status-label">Rejected</span>）&rarr; <span class="status-label">Active</span> &rarr; <span class="status-label">Annual Review</span> &rarr; <span class="status-label">Offboarded</span></p>
    <p>每个阶段都会触发相应的工作流、通知和必要操作。</p>
</div>

<div class="doc-section" id="tprm-assessments">
    <h2>供应商评估</h2>
    <p>供应商评估是发送给供应商以评估其安全态势的安全问卷。导航至 <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Assessments</span> 进行管理。</p>
    <ol class="steps">
        <li>打开供应商详情页面。</li>
        <li>点击 <span class="btn-label">Send Assessment</span>。</li>
        <li>选择适合该供应商等级的评估模板。</li>
        <li>供应商将收到一封含有问卷填写链接的电子邮件。</li>
        <li>提交后，审查供应商的回复并为其评分。</li>
    </ol>
</div>

<div class="doc-section" id="tprm-srs">
    <h2>安全风险评分卡（SRS）</h2>
    <p>SRS 根据 DNS 配置、SSL/TLS、电子邮件安全（SPF、DKIM、DMARC）、开放端口及其他技术指标，为每个供应商提供自动化的外部安全评分。</p>
    <p>导航至 <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Security Risk Scorecard</span>。</p>
</div>

<div class="doc-section" id="tprm-fair">
    <h2>FAIR 分析</h2>
    <p><strong>FAIR</strong>（Factor Analysis of Information Risk，信息风险因素分析）是一种定量风险模型，用于估算供应商安全事件可能造成的财务损失。</p>
    <p>导航至 <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">FAIR Analysis</span> 创建和查看分析。</p>
</div>

<div class="doc-section" id="tprm-fourth-party">
    <h2>第四方风险</h2>
    <p>跟踪<em>您的供应商</em>所依赖的供应商。如果您的云服务提供商使用分包商进行数据存储，这就是第四方风险。</p>
    <p>导航至 <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">4th Party Risk</span>。</p>
</div>

<div class="doc-section" id="tprm-shadow-saas">
    <h2>影子 SaaS 发现</h2>
    <p>发现组织内正在使用但可能未经正式批准或评估的 SaaS 应用。导航至 <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Shadow SaaS</span>。在 v2.6.1 中，此列表可通过 <a href="#shadow-saas-grip">Grip</a> 或 <a href="#shadow-saas-hero">Hero</a> 影子 SaaS 集成自动填充，未经授权的应用可在 <a href="#zscaler">Zscaler</a> 中进行封锁。</p>
</div>


<!-- ================================================================
     VENDOR ONBOARDING & PROCUREMENT (NEW IN 2.6.1)
     ================================================================ -->
<div class="doc-section" id="onboarding-workflow">
    <h2>供应商入驻与采购入驻 <span class="new-badge">2.6.1 新功能</span></h2>
    <p><strong>供应商入驻申请</strong>是新供应商进入平台的方式，通过一系列<strong>状态</strong>从初始草稿推进至最终决策。在网络安全团队审查供应商之前，该供应商必须首先通过您的<strong>采购流程完成入驻</strong>并获得有效的<strong>供应商 ID（VID）</strong>。本节将解释原因及具体操作方式。</p>

    <h3>入驻流程（状态）</h3>
    <table class="doc-table">
        <tr><th>状态</th><th>含义</th></tr>
        <tr><td><span class="status-label">Draft</span></td><td>申请正在填写中，尚未提交审查。</td></tr>
        <tr><td><span class="status-label">Submitted</span></td><td>申请已通过提交检查，已发送给网络安全团队。</td></tr>
        <tr><td><span class="status-label">In Review</span></td><td>网络安全团队正在审查供应商。</td></tr>
        <tr><td><span class="status-label">AI Review</span></td><td>该供应商的服务使用 AI，正处于专属 AI 审查阶段（请参阅 <a href="#ai-review">AI 审查</a>）。</td></tr>
        <tr><td><span class="status-label">Evaluation</span></td><td>供应商正在进行试用或评估。</td></tr>
        <tr><td><span class="status-label">Approved</span></td><td>供应商已获批准并完成入驻。</td></tr>
        <tr><td><span class="status-label">Rejected</span></td><td>供应商未获批准。</td></tr>
        <tr><td><span class="status-label">Inactive</span></td><td>供应商不再活跃。</td></tr>
    </table>

    <h3>查找您的供应商申请</h3>
    <p>前往 <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Stakeholders</span> &rarr; <span class="menu-label">Vendor Onboarding</span>。您将看到供应商的可搜索列表，包含其状态、等级、安全评分（SRS）和快捷操作（View、Edit）。使用顶部的筛选标签（例如 <strong>All</strong>、<strong>Approved</strong>、<strong>Review</strong>）缩小列表范围。使用 <span class="btn-label">+ New Request</span> 开始添加新供应商。</p>
    <figure class="doc-figure">
        <img src="app/docs/vendor-onboarding-list.png" alt="Vendor Onboarding Requests list" loading="lazy">
        <figcaption><strong>供应商入驻列表。</strong>搜索、筛选标签和每个供应商的操作按钮。<strong>Review</strong> 筛选标签是一个综合视图，同时显示 <em>In Review</em> 和 <em>AI Review</em> 状态的供应商。</figcaption>
    </figure>

    <h3 id="procurement-onboarding">每个供应商在审查前需要满足的两个条件</h3>
    <p>打开供应商，查看<strong>供应商信息</strong>卡片。两个字段决定供应商是否可以提交进行网络安全审查：</p>
    <ul>
        <li><strong>Procurement Onboarding</strong> &mdash; 一个是/否字段，回答<em>"该供应商是否已完成采购入驻？"</em>必须设置为<strong>是</strong>。</li>
        <li><strong>Vendor ID（VID）</strong> &mdash; 采购系统分配给供应商的 4&ndash;8 位数字标识符，必须是有效的 4&ndash;8 位数字。</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/vendor-info-card.png" alt="Vendor Information card showing Procurement Onboarding and Vendor ID fields" loading="lazy">
        <figcaption><strong>供应商信息卡片。</strong>供应商提交之前，<strong>Vendor ID（VID）</strong>和采购入驻字段都需要填写。<em>注意：</em>在从早期版本升级的实例中，该字段可能仍显示为 <strong>"VSU Onboarded"</strong>；在 v2.6.1 中标签为 <strong>"Procurement Onboarding"</strong> &mdash; 是同一个字段。</figcaption>
    </figure>

    <h3>提交供应商进行审查</h3>
    <ol class="steps">
        <li>从 <span class="menu-label">Vendor Onboarding</span> 列表中打开供应商（申请必须处于 <strong>Draft</strong> 状态）。</li>
        <li>在<strong>供应商信息</strong>卡片中，将 <span class="field-label">Procurement Onboarding</span> 设置为<strong>是</strong>，并输入有效的 <span class="field-label">Vendor ID（VID）</span>（4&ndash;8 位数字）。保存更改。</li>
        <li>点击 <span class="btn-label">Submit for Review</span>。系统将要求您确认：<em>"Submit this vendor for review? The vendor must have a valid VID and be onboarded at VSU."</em></li>
        <li>如果两项检查都通过，状态将变为 <strong>Submitted</strong>，并通知网络安全团队。</li>
    </ol>
    <div class="callout callout-danger">
        <strong>如果提交被阻止，</strong>您将看到以下消息之一：
        <ul style="margin:8px 0 0;">
            <li>"Cannot submit: Vendor must be onboarded at VSU before submission. Please complete the onboarding assessment with VSU details." &rarr; 请将 <strong>Procurement Onboarding</strong> 设置为<strong>是</strong>。</li>
            <li>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits). Please complete the onboarding assessment with the VSU Vendor ID." &rarr; 请输入有效的 4&ndash;8 位 <strong>Vendor ID</strong>。</li>
        </ul>
        请参阅 <a href="#troubleshooting">故障排除</a>了解此规则存在的原因。
    </div>
</div>

<div class="doc-section" id="ai-review">
    <h2>供应商 AI 审查 <span class="new-badge">2.6.1 新功能</span></h2>
    <p>部分供应商提供使用人工智能的服务。这些供应商可能带来不同的风险，因此 v2.6.1 新增了专属的 <strong>AI Review</strong> 状态，在审查过程中对其进行单独跟踪。</p>

    <h3>供应商如何进入 AI 审查</h3>
    <p>在<strong>供应商信息</strong>卡片上有一个 <span class="field-label">Services Use AI</span> 字段。当此字段设置为<strong>是</strong>时，授权审查员（<strong>Cyber TPRM</strong> 用户或<strong>管理员</strong>，在编辑供应商时）将在该字段正下方看到 <span class="btn-label">Force AI Review</span> 链接。</p>
    <ol class="steps">
        <li>打开供应商并确认 <span class="field-label">Services Use AI</span> 设置为<strong>是</strong>。</li>
        <li>点击 <span class="btn-label">Force AI Review</span>，确认提示：<em>"Force this vendor into AI Review?"</em></li>
        <li>供应商的状态变为 <strong>AI Review</strong>。</li>
    </ol>
    <div class="callout callout-info">
        <strong>为何链接可能不显示？</strong><strong>Force AI Review</strong> 链接仅在以下条件全部满足时显示：（1）您有审批权限，（2）您处于编辑模式，（3）<strong>Services Use AI</strong> 为<strong>是</strong>，（4）供应商尚未处于 AI Review 状态。如果 <strong>Services Use AI</strong> 为"否"，您将看到提示 <em>"AI Review can only be forced for vendors whose services use AI."</em></p>
    </div>
    <p>在 <a href="#procurement-cyber-status">采购网络安全状态</a>页面和供应商列表的 <strong>Review</strong> 筛选器中，处于 <strong>In Review</strong> 和 <strong>AI Review</strong> 状态的供应商会一起显示——因此正在进行 AI 审查的供应商不会因此被隐藏。</p>
</div>

<div class="doc-section" id="procurement-cyber-status">
    <h2>采购网络安全状态 <span class="new-badge">2.6.1 新功能</span></h2>
    <p><strong>网络安全状态</strong>页面为<strong>采购团队</strong>提供一个简洁、实时的视图，显示网络安全团队正在审查哪些供应商以及每个供应商的最新进展——无需访问完整的安全工具。网络安全团队发布简短的带日期更新；采购团队在此处（以及每周电子邮件中）查阅。</p>
    <p>从 <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Procurement</span> &rarr; <span class="menu-label">Cyber Status</span> 打开。<strong>Procurement</strong>、<strong>Cyber TPRM</strong> 和<strong>管理员</strong>用户均可访问。</p>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status.png" alt="Procurement Cyber Status page" loading="lazy">
        <figcaption><strong>Procurement &rarr; Cyber Status。</strong>列出所有状态为 <em>In Review</em> 或 <em>AI Review</em> 的供应商，以及更新次数和最近更新日期。当没有供应商处于审查中时，表格被替换为"No vendors in review"提示。</figcaption>
    </figure>

    <h3>查阅供应商的更新历史</h3>
    <ol class="steps">
        <li>在<strong>待审查供应商</strong>表格中点击供应商名称。</li>
        <li><strong>采购更新历史</strong>面板打开，按时间倒序显示所有更新：日期和时间、撰写者、当时供应商的状态，以及更新内容。</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status-updates.png" alt="Procurement Update History for a vendor in review" loading="lazy">
        <figcaption><strong>供应商的更新历史。</strong>点击供应商名称可打开其<strong>采购更新历史</strong>。每条记录显示日期和时间、作者、记录时供应商状态的标签，以及网络安全团队的备注——让采购团队清楚地了解每项审查的进展。历史记录较长时，可使用 <em>Show&nbsp;per&nbsp;page</em> 控件进行分页。</figcaption>
    </figure>

    <h3>网络安全审查员：向采购团队发布更新</h3>
    <p>Cyber TPRM 用户和管理员可以一次为一个或多个供应商发布更新：</p>
    <ol class="steps">
        <li>在<strong>网络安全状态</strong>页面，勾选您要更新的每个供应商旁边的复选框。</li>
        <li>点击 <span class="btn-label">Provide Procurement with Update</span>。</li>
        <li>在<strong>向采购提供更新</strong>窗口中，在 <span class="field-label">Update</span> 框中输入您的备注。</li>
        <li>可选地使用 <span class="field-label">Change status</span> 将供应商推进到下一阶段（例如推进至 <strong>Evaluation</strong>、<strong>Approved</strong> 或 <strong>Rejected</strong>）。若只添加备注，保留 <em>Keep current status</em>。</li>
        <li>点击 <span class="btn-label">Save Update</span>，更新将记录到所有选定的供应商上。</li>
    </ol>

    <h3>每周采购摘要邮件</h3>
    <p>为了在无需任何人登录的情况下保持采购团队知情，平台可以发送<strong>每周摘要</strong>电子邮件，列出所有待审查供应商及其最新更新。默认情况下，此邮件<strong>每周一上午 7:00</strong> 发送。</p>
    <ol class="steps">
        <li>管理员前往 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email Settings</span>，找到<strong>采购更新摘要</strong>选项。</li>
        <li>开启摘要，并输入一个或多个收件人邮件地址（用逗号分隔）。</li>
        <li>保存。您也可以点击 <span class="btn-label">Send digest now</span> 立即发送一封进行测试。</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-scheduler.png" alt="Admin Scheduler showing the Procurement Update Digest job" loading="lazy">
        <figcaption><strong>Admin &rarr; Scheduler。</strong><strong>Procurement Update Digest</strong> 任务（列表底部）每周运行一次。调度器是管理员启用、禁用和设置所有自动化任务时间的地方。</figcaption>
    </figure>
</div>

<!-- ================================================================
     INTEGRATIONS: GRIP + ZSCALER (NEW IN 2.6.1)
     ================================================================ -->
<div class="doc-section" id="shadow-saas-grip">
    <h2>Grip 影子 SaaS 集成 <span class="new-badge">2.6.1 新功能</span></h2>
    <p>"影子 SaaS"是指员工未经正式批准就在使用的云应用。<strong>Grip Security</strong> 是一项用于发现这些应用的服务。在 v2.6.1 中，您可以连接 Grip 账户，让平台自动拉取 Grip 发现的应用——包括每个应用的用户数量、风险评分和安全警报——并在您的 <a href="#tprm-shadow-saas">影子 SaaS</a> 页面上列出它们。</p>
    <p>管理员在 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> 的 <strong>Grip</strong> 标签页中进行配置。Grip 是两个影子 SaaS 提供商之一（另一个是 <a href="#shadow-saas-hero">Hero</a>），每次只能启用其中一个。</p>

    <h3>连接 Grip（分步操作）</h3>
    <ol class="steps">
        <li>在 Grip 中，创建一个 <strong>API 令牌</strong>，并记下租户的基础 URL（以 <code>/public/saas</code> 结尾，例如 <code>https://acme.dep.grip.security/public/saas</code>）。</li>
        <li>在平台中，前往 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span>，找到 <strong>Grip Security Connection</strong> 卡片。</li>
        <li>勾选 <span class="field-label">Enable Grip Security integration</span>。</li>
        <li>将租户 URL 粘贴到 <span class="field-label">Server (Tenant Base URL)</span>，将令牌粘贴到 <span class="field-label">API Token</span>。</li>
        <li>点击 <span class="btn-label">Save Configuration</span>，然后点击 <span class="btn-label">Test Connection</span> 确认。成功消息类似于 <em>"Connected to Grip — sample returned 1 record(s)"</em>。</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grip-card.png" alt="Grip Security Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Grip Security Connection。</strong>输入您的租户 URL 和 API 令牌，保存后进行测试。</figcaption>
    </figure>

    <h3>自动保持数据更新</h3>
    <p>使用共享的<strong>计划重新加载</strong>卡片（位于提供商标签页下方）来按计划刷新已启用提供商的数据。勾选 <span class="field-label">Enable scheduled rehydration</span> 并输入 <span class="field-label">Schedule (cron expression)</span>——例如 <code>0 2 * * *</code> 表示每天凌晨 2 点；卡片会以通俗易懂的语言显示您所输入的计划摘要。任务将自动安装到系统调度器中（无需手动配置服务器），并在重启后持续生效。您也可以点击 <span class="btn-label">Run Now</span> 立即刷新。同一计划适用于当前启用的任一提供商（Grip 或 Hero）。</p>

    <h3>之后您将看到的内容</h3>
    <p>发现的应用将以<strong>待处理</strong>条目的形式出现在 <span class="menu-label">Shadow SaaS</span> 页面，附带风险评分（以 1&ndash;5 分制显示）、类别和用户数量。您可以<strong>允许</strong>（Allow）某个应用（开始将其作为供应商入驻）、<strong>拒绝</strong>（Deny）（标记为未经授权，并可选择在 Zscaler 中封锁）或<strong>忽略</strong>（Dismiss）。</p>
    <figure class="doc-figure">
        <img src="app/docs/shadow-saas.png" alt="Shadow SaaS list page" loading="lazy">
        <figcaption><strong>影子 SaaS 页面。</strong>已发现和导入的应用，包含风险信息和操作按钮。已作为供应商入驻的应用在后续同步中会被跳过，您忽略的内容会保持忽略状态。</figcaption>
    </figure>
</div>

<div class="doc-section" id="shadow-saas-hero">
    <h2>Hero 影子 SaaS 集成 <span class="new-badge">2.6.1 新功能</span></h2>
    <p><strong>HERO Security</strong> 是另一种影子 SaaS 提供商。您可以连接 HERO 账户（而非 Grip），平台将把 HERO 发现的供应商——包括状态、风险评分、最活跃联系人和用户数量——拉取到同一个 <a href="#tprm-shadow-saas">影子 SaaS</a> 列表中。Grip 和 Hero <strong>互斥</strong>：启用 Hero 会自动禁用 Grip（反之亦然），因此列表始终只由一个提供商提供数据。</p>
    <p>管理员在 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> 的 <strong>Hero</strong> 标签页中进行配置。</p>

    <h3>连接 Hero（分步操作）</h3>
    <ol class="steps">
        <li>在 HERO 管理面板中，创建一个 <strong>API 客户端</strong>，并复制其 <strong>Client ID</strong> 和 <strong>Client Secret</strong>（密钥仅显示一次）。</li>
        <li>在平台中，前往 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span>，打开 <strong>Hero</strong> 标签页，找到 <strong>HERO Security Connection</strong> 卡片。</li>
        <li>勾选 <span class="field-label">Enable HERO Security integration</span>（这将禁用 Grip）。</li>
        <li>除非另有说明，保持 <span class="field-label">Server (Base URL)</span> 为 <code>https://api.herosecurity.ai/stable</code>，并粘贴您的 <span class="field-label">Client ID</span> 和 <span class="field-label">Client Secret</span>。</li>
        <li>点击 <span class="btn-label">Save Configuration</span>，然后点击 <span class="btn-label">Test Connection</span>。成功消息类似于 <em>"Connected to HERO — sample returned 1 record(s)"</em>。</li>
    </ol>

    <h3>之后您将看到的内容</h3>
    <p>HERO 供应商以与 Grip 应用相同的方式出现在 <span class="menu-label">Shadow SaaS</span> 页面——作为您可以允许、拒绝或忽略的<strong>待处理</strong>条目。对于每个供应商，平台记录：</p>
    <ul>
        <li><strong>风险评分（1&ndash;5）</strong> &mdash; 根据 HERO 对该供应商发现的最严重未解决安全问题推导（严重 = 5，低 = 2；没有未解决问题的供应商不评分）。与 Grip 使用相同的 1&ndash;5 分制。</li>
        <li><strong>关系经理</strong> &mdash; 观察到的该供应商最活跃联系人（邮件活动量最高的用户）。</li>
        <li><strong>用户数量</strong> &mdash; 观察到与该供应商交互的用户数量。</li>
        <li><strong>风险类型</strong> &mdash; HERO 参与信号（授权、活动、商业参与）的摘要以及未解决问题数量。</li>
    </ul>
    <p>其他来源提供的某些列（应用类别、MFA 支持、泄露历史、流量数据、文件共享）不在 HERO API 范围内，因此 Hero 来源的条目中这些列保持空白。</p>

    <div class="callout callout-info">
        <strong>关于同步时间的提示。</strong>HERO 按供应商逐一返回数据，且对请求有速率限制，因此大型租户的完整刷新可能需要在后台运行几分钟。计划任务和"立即运行"都会自动控制节奏，以保持在 HERO 的限制范围内。
    </div>
</div>

<div class="doc-section" id="zscaler">
    <h2>Zscaler 封锁集成 <span class="new-badge">2.6.1 新功能</span></h2>
    <p><strong>Zscaler</strong> 是一种可以封锁网站访问的网络安全服务。通过此集成，当您在影子 SaaS 页面<strong>拒绝</strong>一个未经授权的应用时，平台可以自动将该应用的网络域名添加到您的 Zscaler 账户封锁列表中——使人们无法再访问该网站。之后点击<strong>允许</strong>将解除封锁。</p>
    <p>管理员在 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> 的 <strong>Zscaler Connection</strong> 卡片中进行配置。</p>

    <h3>连接 Zscaler（分步操作）</h3>
    <ol class="steps">
        <li>在 Zscaler（ZIdentity）中，创建一个 <strong>API 客户端</strong>，并复制其 <strong>Client ID</strong> 和 <strong>Client Secret</strong>。记下您的<strong>虚荣域名</strong>（<code>.zslogin.net</code> 之前的部分）。</li>
        <li>在 ZIA 中，创建（或选择）一个<strong>自定义 URL 类别</strong>，被封锁的域名将被添加到该类别，并记下其确切名称。</li>
        <li>在平台的 <strong>Zscaler Connection</strong> 卡片中，勾选 <span class="field-label">Enable Zscaler URL-Category blocking on Deny</span>。</li>
        <li>填写 <span class="field-label">API URL</span>（默认 <code>https://api.zsapi.net</code>）、<span class="field-label">ZIdentity Vanity Domain</span>、<span class="field-label">Client ID</span>、<span class="field-label">Client Secret</span> 和 <span class="field-label">URL Category</span> 名称。</li>
        <li>点击 <span class="btn-label">Save Configuration</span>，然后点击 <span class="btn-label">Test Connection</span> 确认凭据有效。</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/zscaler-card.png" alt="Zscaler Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Zscaler Connection。</strong>启用后，在影子 SaaS 应用上点击 <strong>Deny</strong> 按钮会将其域名添加到您在此指定的 URL 类别中。该类别必须已在 Zscaler 中存在。</figcaption>
    </figure>
    <div class="callout callout-info">
        <strong>如果封锁功能已关闭，</strong>拒绝应用只会在平台中将其标记为未经授权；不会向 Zscaler 发送任何内容。您将看到 <em>"Marked unsanctioned. Zscaler integration is not enabled; domain not added to URL Category."</em>
    </div>
</div>


<!-- ================================================================
     ADMIN PORTAL
     ================================================================ -->
<div class="doc-section" id="admin-general">
    <h2>管理门户：常规设置</h2>
    <p>管理门户可通过侧边栏的 <span class="menu-label">Administration</span>（仅管理员用户）或顶部栏的 <span class="btn-label">Admin</span> 链接访问。</p>
    <p>常规设置包括：应用名称、公司名称、支持邮箱及系统级配置选项。</p>
</div>

<div class="doc-section" id="admin-branding">
    <h2>品牌与主题</h2>
    <p>自定义平台外观：上传公司徽标，设置侧边栏颜色、标题颜色、按钮颜色和导航宽度。导航至 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Branding</span>。</p>
</div>

<div class="doc-section" id="admin-users">
    <h2>用户管理</h2>
    <p>管理用户账户和群组分配。导航至 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span>。</p>

    <h3>将用户分配到 ACL 群组</h3>
    <ol class="steps">
        <li>导航至 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span>。</li>
        <li>在列表中找到该用户。</li>
        <li>点击用户名旁边的 <span class="btn-label">Groups</span> 按钮。</li>
        <li>将出现一个模态框，显示所有可用群组及复选框。勾选您要分配的群组（例如 <strong>Cyber GRC</strong>、<strong>Administrator</strong>）。</li>
        <li>点击 <span class="btn-label">Save Changes</span>。</li>
    </ol>
</div>

<div class="doc-section" id="admin-email">
    <h2>邮件配置</h2>
    <p>配置发送电子邮件通知的 SMTP 设置。导航至 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email</span>。设置包括 SMTP 主机、端口、用户名、密码、加密方式（TLS/SSL）和发件人地址。</p>
</div>

<div class="doc-section" id="admin-saml">
    <h2>SAML / SSO</h2>
    <p>使用 SAML 2.0 配置单点登录。导航至 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span>，允许用户使用您组织的身份提供商（Okta、Azure AD 等）登录。</p>
    <ol class="steps">
        <li>勾选 <span class="field-label">Enable SAML 2.0</span>。</li>
        <li>填写所有必填的身份提供商字段（<span class="field-label">IdP Entity ID</span>、<span class="field-label">IdP Single Sign-On URL</span>、<span class="field-label">IdP X.509 Certificate</span>）和服务提供商字段（<span class="field-label">SP Entity ID</span>、<span class="field-label">SP ACS URL</span>）。只有当<strong>所有</strong>字段都填写完整后，SSO 才会激活——部分填写的表单保持禁用状态。</li>
        <li>点击 <span class="btn-label">Save</span>。</li>
    </ol>

    <h3>同时运行本地登录和 SSO</h3>
    <p>默认情况下，启用 SSO <strong>不会</strong>关闭本地用户名/密码表单——登录页面同时显示 <strong>Sign in with SSO</strong> 按钮<em>和</em>本地登录选项，两者可并排使用。此行为由单个本地登录开关控制：</p>
    <table>
        <tr><th>模式</th><th>用户看到的内容</th></tr>
        <tr><td><strong>本地登录已启用</strong>（默认）</td><td>SSO 按钮<em>和</em>用户名/密码表单，可同时使用。</td></tr>
        <tr><td><strong>本地登录已禁用</strong>（仅 SSO）</td><td>SSO 是普通用户的唯一登录方式。指定的<strong>紧急访问管理员</strong>账户仍可本地登录，确保身份提供商出现问题时不会将所有人锁定在外。</td></tr>
    </table>
    <p>如果 SAML 实际上未配置，该开关将被忽略，本地登录始终可用（防锁定安全网）。</p>

    <h3>紧急访问：同时允许 SAML 和本地登录（配置文件） <span class="new-badge">2.6.1 新功能</span></h3>
    <p>本地登录开关有两种设置方式。配置文件设置（如果存在）<strong>优先于数据库值</strong>——这是一种无需数据库访问的紧急访问控制，因此即使 SSO 出现问题，您也可以随时恢复本地登录。</p>
    <table>
        <tr><th>位置</th><th>方法</th></tr>
        <tr><td>Admin &rarr; SAML 页面</td><td>在<strong>连接设置</strong>卡片中，勾选或取消勾选 <span class="field-label">Allow local username/password login (in addition to SSO)</span>，然后点击 <span class="btn-label">Save SAML Configuration</span>。这将写入 <code>local_login_enabled</code> 设置（默认启用）——无需 SQL 操作。</td></tr>
        <tr><td>配置文件（优先生效）</td><td>在 <code>config/config.php</code> 的 <code>auth</code> 块中，设置 <code>'local_login_enabled' =&gt; true</code> 可使本地登录始终可用（本地 + SSO 并用），或设置为 <code>false</code> 仅使用 SSO。此设置<strong>覆盖</strong>上方的切换开关；设置期间，SAML 页面上的复选框显示为只读。删除该行后即可再次从界面管理。编辑 <code>config.php</code> 后需重启容器。</td></tr>
    </table>
    <p>要在<strong>同时运行 SAML 和本地登录</strong>且不进行数据库更改的情况下，按上述方式配置 SAML，并将以下内容添加到 <code>config/config.php</code> 的 <code>auth</code> 块中，然后重启容器：</p>
    <pre><code>'auth' =&gt; [
    // ...
    // Break-glass: true = local login always available alongside SSO;
    // false = SSO-only (break-glass admin can still log in locally).
    'local_login_enabled' =&gt; true,
],</code></pre>
</div>

<div class="doc-section" id="admin-ai">
    <h2>AI 集成</h2>
    <p>启用 AI 驱动的功能，包括评估备注优化、控制措施建议、供应商评注、AI 辅助 FAIR 风险分析和报告语言辅助。导航至 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">AI Platform</span> 选择提供商并输入 API 密钥，每次只有一个平台处于活跃状态。</p>
    <p>支持的 AI 平台：</p>
    <ul>
        <li><strong>Anthropic (Claude)</strong> <span class="new-badge">2.6.1 新功能</span> &mdash; 直接连接到 Claude 的原生 API（例如 <code>claude-opus-4-8</code>）。粘贴您的 Anthropic API 密钥；端点默认为标准 Messages URL。支持实时<strong>网络搜索</strong>，因此违规警报和 OSINT 扫描基于当前的引用来源。</li>
        <li><strong>OpenAI (ChatGPT)</strong> <span class="new-badge">2.6.1 新功能</span> &mdash; 直接连接到 OpenAI（例如 <code>gpt-4o</code>）。粘贴您的 OpenAI API 密钥。对于违规和 OSINT 扫描，使用具有网络搜索功能的模型（默认 <code>gpt-4o-search-preview</code>），确保扫描基于实时来源。</li>
        <li><strong>OpenWebUI</strong> &mdash; 针对 OpenAI 兼容端点的 JWT 持有者令牌。</li>
        <li><strong>LibreChat</strong> &mdash; API 密钥身份验证，基于代理；代理自行管理其模型和采样。</li>
        <li><strong>自定义</strong> &mdash; 粘贴 curl 风格的标题和正文模板，适用于任何其他 OpenAI 兼容（或编排器）端点。</li>
    </ul>
    <p><strong>选择和加载模型：</strong>输入并<strong>保存</strong>密钥后，点击该平台卡片上的 <span class="btn-label">Load Models</span> 获取其可用模型列表（OpenWebUI / LibreChat / OpenAI）。对于 Anthropic，请直接输入模型名称（例如 <code>claude-opus-4-8</code>）。</p>
    <p><strong>违规警报基础：</strong>违规和 OSINT 扫描器需要能够搜索网络的提供商。<strong>Anthropic (Claude)</strong> 和 <strong>OpenAI (ChatGPT)</strong> 均原生支持；OpenWebUI / LibreChat 仅在底层代理具有浏览功能时支持；自定义平台仅在配置了网络搜索 URL 时支持。</p>
    <p>活跃的 AI 提供商还为<strong>评估问题的自动翻译</strong>提供支持（请参阅 <a href="#language">更改语言</a>）。</p>
</div>

<div class="doc-section" id="admin-updates">
    <h2>平台更新 <span class="new-badge">2.6.1 新功能</span></h2>
    <p>管理员可以在平台内部检查并应用新版本。导航至 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span>。</p>
    <ol class="steps">
        <li><strong>当前状态</strong>卡片显示您的 <span class="field-label">已安装版本</span>以及是否有更新版本可用。</li>
        <li>确认 <span class="field-label">Registry Hostname</span>（您的镜像注册表）正确，然后点击 <span class="btn-label">Check for Updates</span>。</li>
        <li>如果列出了新版本，请按照屏幕上的<strong>升级</strong>操作进行应用。</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-version.png" alt="Version Management page showing installed version 2.6.1" loading="lazy">
        <figcaption><strong>Admin &rarr; Version。</strong>此处安装的版本为 <strong>v2.6.1</strong>，平台报告已是最新版本。这也是确认本指南适用版本的地方。</figcaption>
    </figure>
</div>


<!-- ================================================================
     FAQ (SEARCHABLE)
     ================================================================ -->
<div class="doc-section" id="faq">
    <h2>常见问题</h2>
    <p>在下方输入关键词可即时筛选问题——例如<em>语言</em>、<em>VID</em>、<em>入驻</em>、<em>Grip</em> 或<em>密码</em>。</p>
    <div class="faq-search-wrap">
        <input type="text" id="faqSearch" class="faq-search" placeholder="搜索常见问题&hellip;" aria-label="Search frequently asked questions" autocomplete="off">
    </div>
    <p class="faq-count" id="faqCount"></p>
    <div id="faqList">

        <details class="faq-item"><summary>用户可以同时使用 SSO 和本地密码登录吗？<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>可以。默认情况下，启用 SAML/SSO <strong>不会</strong>关闭本地登录——登录页面同时显示 <strong>Sign in with SSO</strong> 按钮和本地用户名/密码选项。您可以通过 <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span> 页面上的 <strong>Allow local username/password login</strong> 复选框控制此行为（勾选则两种方式并用；取消勾选则仅 SSO，但紧急访问管理员仍可本地登录）。要进行无需数据库的紧急访问控制，可通过 <code>config/config.php</code> 中的 <code>'local_login_enabled' =&gt; true</code> 强制该设置，此设置优先于复选框。请参阅 <a href="#admin-saml">SAML / SSO</a>。</p></div></details>

        <details class="faq-item"><summary>SSO 配置错误，无人能够登录。如何恢复访问？<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>使用紧急访问控制：在 <code>config/config.php</code> 的 <code>auth</code> 块中，设置 <code>'local_login_enabled' =&gt; true</code> 并重启容器。这将重新启用本地用户名/密码表单，无论数据库设置如何，您都可以登录并修复 SAML 配置。请参阅 <a href="#admin-saml">SAML / SSO</a>。</p></div></details>

        <details class="faq-item"><summary>如何更改平台语言？<span class="faq-tag">语言</span></summary>
            <div class="faq-body"><p>点击右上角的 <strong>Profile</strong>，打开 <strong>Language Preference</strong> 卡片，选择您的语言，然后点击 <strong>Update Language</strong>。这只会更改您自己的界面。请参阅 <a href="#language">更改语言</a>。</p></div></details>

        <details class="faq-item"><summary>支持哪些语言？<span class="faq-tag">语言</span></summary>
            <div class="faq-body"><p>英语、西班牙语、意大利语、乌克兰语、中文（简体）、印地语、法语和葡萄牙语。您的管理员决定这些语言中哪些出现在您的列表中；英语始终可用。</p></div></details>

        <details class="faq-item"><summary>我更改了语言，但部分文字仍显示英语。这是为什么？<span class="faq-tag">语言</span></summary>
            <div class="faq-body"><p>菜单、按钮和标签均已翻译。供应商评估<em>问题</em>仅在管理员配置了 AI 提供商的情况下才会自动翻译，而存储的答案值始终保持英文以确保评分一致性。部分第三方消息也可能保持英文显示。</p></div></details>

        <details class="faq-item"><summary>为什么我无法提交供应商进行审查？<span class="faq-tag">入驻</span></summary>
            <div class="faq-body"><p>供应商只有在完成<strong>采购入驻</strong>（设置为<strong>是</strong>）并拥有有效的 4&ndash;8 位<strong>供应商 ID（VID）</strong>后才能提交。打开供应商，在<strong>供应商信息</strong>卡片上填写这两项，保存后再次点击<strong>提交审查</strong>。请参阅 <a href="#onboarding-workflow">供应商入驻</a> 和 <a href="#troubleshooting">故障排除</a>。</p></div></details>

        <details class="faq-item"><summary>什么是供应商 ID（VID），从哪里获取？<span class="faq-tag">入驻</span></summary>
            <div class="faq-body"><p>VID 是供应商入驻时由采购系统分配给供应商的 4&ndash;8 位数字，它将此处的供应商与您的采购和财务记录关联起来。如果没有 VID，说明供应商尚未完成采购入驻。</p></div></details>

        <details class="faq-item"><summary>我的供应商页面显示的是"VSU Onboarded"，但指南上写的是"Procurement Onboarding"。哪个是正确的？<span class="faq-tag">入驻</span></summary>
            <div class="faq-body"><p>它们是同一个字段。在 v2.6.1 中重命名为更清晰的 <strong>"Procurement Onboarding"</strong>。如果您的界面仍显示 <strong>"VSU Onboarded"</strong>，说明您的实例尚未升级到最新的 v2.6.1 镜像——功能完全相同。</p></div></details>

        <details class="faq-item"><summary>供应商的"AI Review"状态是什么意思？<span class="faq-tag">AI 审查</span></summary>
            <div class="faq-body"><p>这是专为服务使用 AI 的供应商设立的独立审查状态，以便与普通审查分开追踪。Cyber TPRM 用户或管理员使用 <strong>Force AI Review</strong> 链接将供应商移入此状态。请参阅 <a href="#ai-review">供应商 AI 审查</a>。</p></div></details>

        <details class="faq-item"><summary>我看不到"Force AI Review"链接。这是为什么？<span class="faq-tag">AI 审查</span></summary>
            <div class="faq-body"><p>该链接仅在您具有审批权限且正在编辑供应商、供应商的 <strong>Services Use AI</strong> 字段为<strong>是</strong>、且供应商尚未处于 AI Review 状态时才会显示。</p></div></details>

        <details class="faq-item"><summary>采购网络安全状态页面是什么？<span class="faq-tag">采购</span></summary>
            <div class="faq-body"><p>这是一个通俗易懂的页面（<strong>TPRM &rarr; Procurement &rarr; Cyber Status</strong>），采购团队可以查看网络安全团队正在审查哪些供应商，以及阅读网络安全团队发布的带日期更新。请参阅 <a href="#procurement-cyber-status">采购网络安全状态</a>。</p></div></details>

        <details class="faq-item"><summary>采购团队如何收到更新邮件？<span class="faq-tag">采购</span></summary>
            <div class="faq-body"><p>管理员在 <strong>Admin &rarr; Email Settings</strong> 下开启 <strong>Procurement Update Digest</strong> 并添加收件人地址。邮件每周发送一次（默认周一上午 7:00），也可按需立即发送。</p></div></details>

        <details class="faq-item"><summary>Grip 是什么，在这里有什么作用？<span class="faq-tag">集成</span></summary>
            <div class="faq-body"><p>Grip Security 发现组织内使用的 SaaS 应用。连接后（<strong>Admin &rarr; Shadow SaaS</strong>），平台会自动将这些应用、用户数量、风险评分和警报拉取到您的影子 SaaS 列表中。请参阅 <a href="#shadow-saas-grip">Grip 影子 SaaS 集成</a>。</p></div></details>

        <details class="faq-item"><summary>Grip 和 Hero 集成有什么区别？<span class="faq-tag">集成</span></summary>
            <div class="faq-body"><p>两者都从第三方发现服务（Grip Security 或 HERO Security）向同一影子 SaaS 列表提供数据，并共享 Zscaler 封锁和计划重新加载任务。它们<strong>互斥</strong>：启用其中一个会禁用另一个，因此您只需运行组织所使用的提供商。请参阅 <a href="#shadow-saas-hero">Hero 影子 SaaS 集成</a>。</p></div></details>

        <details class="faq-item"><summary>我的 Grip"测试连接"失败。应该检查什么？<span class="faq-tag">集成</span></summary>
            <div class="faq-body"><p>确认 <strong>Server（Tenant Base URL）</strong>以 <code>/public/saas</code> 结尾，<strong>API Token</strong> 是最新的，以及您的服务器可以访问 Grip 端点。令牌错误会报告 <em>"Unauthorized — token rejected"</em>；URL 错误会报告 <em>"Endpoint not found — check base URL"</em>。</p></div></details>

        <details class="faq-item"><summary>Zscaler 集成有什么作用？<span class="faq-tag">集成</span></summary>
            <div class="faq-body"><p>当您<strong>拒绝</strong>一个未经授权的应用时，平台可以将其网络域名添加到您的 Zscaler 账户中的封锁 URL 类别，使人们无法访问它。之后点击<strong>允许</strong>将解除封锁。请参阅 <a href="#zscaler">Zscaler 封锁集成</a>。</p></div></details>

        <details class="faq-item"><summary>影子 SaaS 应用上"允许"、"拒绝"和"忽略"有什么区别？<span class="faq-tag">集成</span></summary>
            <div class="faq-body"><p><strong>允许（Allow）</strong>开始将该应用作为供应商入驻；<strong>拒绝（Deny）</strong>将其标记为未经授权（并可在 Zscaler 中封锁）；<strong>忽略（Dismiss）</strong>从列表中隐藏它。被忽略的应用在后续同步后仍保持忽略状态。</p></div></details>

        <details class="faq-item"><summary>谁可以看到 GRC 模块？<span class="faq-tag">访问权限</span></summary>
            <div class="faq-body"><p>属于 <strong>Administrator</strong>、<strong>Cyber GRC</strong> 或 <strong>Auditor</strong> 群组的用户。如果您看不到该模块，请联系管理员将您添加到其中一个群组。请参阅 <a href="#roles">用户角色与权限</a>。</p></div></details>

        <details class="faq-item"><summary>如何开启双因素身份验证（2FA）？<span class="faq-tag">账户</span></summary>
            <div class="faq-body"><p>打开 <strong>Profile</strong>，使用 <strong>Two-Factor Authentication（TOTP）</strong>卡片，通过 Google Authenticator 或 Microsoft Authenticator 等身份验证器应用启用。</p></div></details>

        <details class="faq-item"><summary>我可以保存或打印此文档吗？<span class="faq-tag">常规</span></summary>
            <div class="faq-body"><p>可以。点击本页顶部的 <strong>Download PDF</strong>，将生成包含封面、目录和页码的格式化文档。</p></div></details>

        <details class="faq-item"><summary>如何确认我当前运行的版本？<span class="faq-tag">常规</span></summary>
            <div class="faq-body"><p>管理员可以查看 <strong>Admin &rarr; Version</strong>。本指南描述的是 <strong>v2.6.1</strong>。请参阅 <a href="#admin-updates">平台更新</a>。</p></div></details>

    </div>
    <p class="faq-no-results" id="faqNoResults">没有与您搜索内容匹配的问题，请尝试其他关键词。</p>
</div>


<!-- ================================================================
     TROUBLESHOOTING
     ================================================================ -->
<div class="doc-section" id="troubleshooting">
    <h2>故障排除</h2>

    <h3>为什么通过采购流程入驻至关重要（VID 和采购入驻规则）</h3>
    <p>这是阻止供应商推进的最常见原因，因此值得深入了解。平台<strong>不允许供应商提交进行网络安全审查</strong>，直到在供应商记录上记录了两项采购信息：</p>
    <ul>
        <li><strong>Procurement Onboarding = 是</strong> &mdash; 确认供应商已通过您组织的采购流程完成设置和审查。</li>
        <li>有效的<strong>供应商 ID（VID）</strong> &mdash; 采购分配给供应商的 4&ndash;8 位数字。</li>
    </ul>
    <p>为什么要强制执行此规则？因为 VID 是将此供应商与采购、财务和合同记录关联的共享键。如果网络安全团队审查并批准了一个采购从未入驻的供应商，就会出现重复或"幽灵"供应商、无法追溯到实际采购订单的安全工作，以及无法对账的报告。先要求完成采购入驻，可确保安全审查和采购记录指向同一个真实的供应商。</p>
    <div class="callout callout-warning">
        <strong>修复方法：</strong>打开供应商，在<strong>供应商信息</strong>卡片上将 <strong>Procurement Onboarding</strong> 设置为<strong>是</strong>，并从采购系统输入 4&ndash;8 位<strong>供应商 ID（VID）</strong>。保存后，再次点击<strong>提交审查</strong>。如果您还没有 VID，说明供应商尚未完成采购入驻——请先从那里开始。
    </div>

    <h3>常见问题及解决方法</h3>
    <table class="doc-table">
        <tr><th>症状</th><th>可能原因与修复方法</th></tr>
        <tr><td>"Cannot submit: Vendor must be onboarded at VSU before submission&hellip;"</td><td><strong>Procurement Onboarding</strong> 字段未设置为<strong>是</strong>。在供应商信息卡片上将其设置为是并保存。</td></tr>
        <tr><td>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits)&hellip;"</td><td><strong>Vendor ID</strong> 缺失或不是 4&ndash;8 位数字。请从采购系统输入有效的 VID。</td></tr>
        <tr><td>"Only draft requests can be submitted for review."</td><td>供应商已超出草稿阶段。您只能提交仍处于 <strong>Draft</strong> 状态的申请。</td></tr>
        <tr><td><strong>Submit for Review</strong> 按钮不可见</td><td>该按钮仅在您具有编辑权限且供应商处于 <strong>Draft</strong> 状态时显示。</td></tr>
        <tr><td>"AI Review can only be forced for vendors whose services use AI."</td><td>在强制 AI 审查之前，请将供应商的 <strong>Services Use AI</strong> 设置为<strong>是</strong>。</td></tr>
        <tr><td>我在侧边栏中看不到 GRC 模块</td><td>您需要属于 <strong>Administrator</strong>、<strong>Cyber GRC</strong> 或 <strong>Auditor</strong> 群组。请联系管理员。</td></tr>
        <tr><td>我的语言更改未被保存</td><td>确认您点击了 <strong>Update Language</strong>（而不仅仅是更改了下拉选择），并且该语言已被管理员启用。</td></tr>
        <tr><td>Grip"测试连接"失败</td><td>检查基础 URL 是否以 <code>/public/saas</code> 结尾，以及 API 令牌是否有效且最新。</td></tr>
        <tr><td>拒绝影子 SaaS 应用未在 Zscaler 中封锁它</td><td>必须启用并配置 Zscaler 封锁，且指定的 <strong>URL Category</strong> 必须已在 Zscaler 中存在。</td></tr>
        <tr><td>采购团队未收到摘要邮件</td><td>确认在 <strong>Admin &rarr; Email Settings</strong> 下已启用摘要并设置了收件人，以及 <strong>Admin &rarr; Email</strong> 的 SMTP 设置正确。</td></tr>
        <tr><td>升级按钮显示"Could not fetch manifest"</td><td>访问您的镜像注册表时出现注册表/网络问题。请验证 <strong>Admin &rarr; Version</strong> 下的 <strong>Registry Hostname</strong>，并确认主机可以访问该注册表。</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>仍然遇到问题？</strong>记下屏幕上的确切消息以及您所在的页面，然后联系您的平台管理员。管理员可以查看 <strong>Admin &rarr; Activity Log</strong> 以获取详细信息。
    </div>
</div>


<!-- ================================================================
     GLOSSARY
     ================================================================ -->
<div class="doc-section" id="glossary">
    <h2>术语表</h2>
    <table class="doc-table">
        <tr><th>术语</th><th>定义</th></tr>
        <tr><td><strong>ACL</strong></td><td>访问控制列表（Access Control List）&mdash; 定义群组中用户可执行的操作</td></tr>
        <tr><td><strong>AI Review</strong></td><td>供应商入驻状态，用于服务使用 AI 的供应商，在审查过程中单独跟踪</td></tr>
        <tr><td><strong>Assessment</strong></td><td>使用统一问卷进行的某一时间点合规评估</td></tr>
        <tr><td><strong>CIS Controls</strong></td><td>互联网安全中心控制措施（Center for Internet Security Controls）&mdash; 一套优先级安全最佳实践</td></tr>
        <tr><td><strong>CMMC</strong></td><td>网络安全成熟度模型认证（Cybersecurity Maturity Model Certification）&mdash; 美国国防部承包商的必要要求</td></tr>
        <tr><td><strong>Conformity Status</strong></td><td>要求是否为 Conforming、Partial、Non-Conforming、Not Applicable 或 Not Assessed</td></tr>
        <tr><td><strong>Control</strong></td><td>为满足合规要求而实施的具体安全措施</td></tr>
        <tr><td><strong>Crosswalk</strong></td><td>两个框架之间的映射，显示哪些要求相互重叠</td></tr>
        <tr><td><strong>CSF</strong></td><td>NIST Cybersecurity Framework &mdash; 广泛使用的网络安全风险管理框架</td></tr>
        <tr><td><strong>Domain</strong></td><td>安全问题的类别（例如治理、身份与访问管理）</td></tr>
        <tr><td><strong>Evidence</strong></td><td>证明合规声明的文档、截图或文件</td></tr>
        <tr><td><strong>FAIR</strong></td><td>信息风险因素分析（Factor Analysis of Information Risk）&mdash; 一种定量风险分析方法</td></tr>
        <tr><td><strong>FairScore</strong></td><td>平台根据评估回复计算的总体成熟度评分</td></tr>
        <tr><td><strong>Finding</strong></td><td>审计过程中发现的问题（不合规、观察、改进机会或优势）</td></tr>
        <tr><td><strong>Framework</strong></td><td>合规标准，例如 SOC 2、ISO 27001、PCI DSS 等</td></tr>
        <tr><td><strong>GRC</strong></td><td>治理、风险与合规（Governance, Risk, and Compliance）</td></tr>
        <tr><td><strong>Grip</strong></td><td>Grip Security &mdash; 一种发现正在使用的 SaaS 应用的服务；可为影子 SaaS 列表提供数据（请参阅 <a href="#shadow-saas-grip">Grip 影子 SaaS 集成</a>）</td></tr>
        <tr><td><strong>Hero</strong></td><td>HERO Security &mdash; 可为影子 SaaS 列表提供数据的替代影子 SaaS 发现服务（与 Grip 互斥；请参阅 <a href="#shadow-saas-hero">Hero 影子 SaaS 集成</a>）</td></tr>
        <tr><td><strong>HIPAA</strong></td><td>健康保险流通与责任法案（Health Insurance Portability and Accountability Act）&mdash; 美国医疗数据保护法律</td></tr>
        <tr><td><strong>ISO 27001</strong></td><td>信息安全管理体系国际标准</td></tr>
        <tr><td><strong>Maturity Rating</strong></td><td>1-4 分的评分，表示安全实践的成熟度（1=临时性，4=优化）</td></tr>
        <tr><td><strong>NIST 800-171</strong></td><td>NIST 保护受控未分类信息（CUI）的指南</td></tr>
        <tr><td><strong>PCI DSS</strong></td><td>支付卡行业数据安全标准（Payment Card Industry Data Security Standard）</td></tr>
        <tr><td><strong>PII</strong></td><td>个人可识别信息（Personally Identifiable Information）（姓名、电子邮件、地址等）</td></tr>
        <tr><td><strong>Procurement Onboarding</strong></td><td>确认（是/否）供应商已通过采购流程完成设置；与有效的 VID 一起，是供应商提交审查前的必要条件。（在从早期版本升级的实例中标记为"VSU Onboarded"。）</td></tr>
        <tr><td><strong>Requirement</strong></td><td>合规框架中的特定条款或控制目标</td></tr>
        <tr><td><strong>SaaS</strong></td><td>软件即服务（Software as a Service）&mdash; 通过网络访问的云应用</td></tr>
        <tr><td><strong>Shadow SaaS</strong></td><td>组织内使用的、从未经过正式批准或评估的 SaaS 应用</td></tr>
        <tr><td><strong>SOC 2</strong></td><td>服务组织控制 2 型（Service Organization Control Type 2）&mdash; 服务机构的信任服务标准</td></tr>
        <tr><td><strong>SPII</strong></td><td>敏感个人可识别信息（社会安全号码、财务数据、健康记录）</td></tr>
        <tr><td><strong>SRS</strong></td><td>安全风险评分卡（Security Risk Scorecard）&mdash; 平台对供应商的外部安全评级/评分</td></tr>
        <tr><td><strong>TPRM</strong></td><td>第三方风险管理（Third Party Risk Management）</td></tr>
        <tr><td><strong>Unified Question</strong></td><td>映射到多个框架要求的单个安全问题</td></tr>
        <tr><td><strong>VID</strong></td><td>供应商 ID（Vendor ID）&mdash; 采购系统分配给供应商的 4&ndash;8 位标识符</td></tr>
        <tr><td><strong>VSU</strong></td><td>采购/供应商设置职能；"onboarded at VSU"意味着供应商已完成采购入驻</td></tr>
        <tr><td><strong>Zscaler</strong></td><td>一种可封锁网站域名的网络安全服务；集成后，未经授权的应用可在拒绝时被封锁（请参阅 <a href="#zscaler">Zscaler 封锁</a>）</td></tr>
    </table>
</div>


                </div><!-- /.doc-body -->

                <!-- Sticky right-side TOC (screen only) -->
                <nav class="doc-sidebar-toc" id="sidebarToc">
                    <h4>入门</h4>
                    <a href="#overview">平台概述</a>
                    <a href="#navigation">侧边栏导航</a>
                    <a href="#roles">用户角色与权限</a>
                    <a href="#first-login">首次登录</a>
                    <a href="#whats-new">2.6.1 新功能</a>
                    <a href="#language">更改语言</a>
                    <a href="#question-types">电话和 VAT 问题类型</a>

                    <h4>GRC — 快速入门</h4>
                    <a href="#grc-overview">什么是 GRC？</a>
                    <a href="#grc-getting-started">入门</a>
                    <a href="#grc-step1">第 1 步：创建评估</a>
                    <a href="#grc-step2">第 2 步：回答问题</a>
                    <a href="#grc-step3">第 3 步：上传证据</a>
                    <a href="#grc-step4">第 4 步：查看评分</a>
                    <a href="#grc-step5">第 5 步：生成报告</a>

                    <h4>GRC — 功能</h4>
                    <a href="#grc-fairscore">CSF 成熟度评分</a>
                    <a href="#grc-gaps">差距分析</a>
                    <a href="#grc-frameworks">框架</a>
                    <a href="#grc-controls">内部控制措施</a>
                    <a href="#grc-crosswalk">框架交叉对照</a>
                    <a href="#grc-evidence">证据库</a>
                    <a href="#grc-policies">策略管理</a>
                    <a href="#grc-audits">审计与发现</a>
                    <a href="#grc-risks">风险登记册</a>
                    <a href="#grc-monitors">持续监控</a>
                    <a href="#grc-tasks">任务收件箱</a>
                    <a href="#grc-dashboard">GRC 仪表盘</a>

                    <h4>TPRM 模块</h4>
                    <a href="#tprm-overview">什么是 TPRM？</a>
                    <a href="#tprm-add-vendor">添加供应商</a>
                    <a href="#tprm-lifecycle">供应商生命周期</a>
                    <a href="#tprm-assessments">供应商评估</a>
                    <a href="#tprm-srs">安全风险评分卡</a>
                    <a href="#tprm-fair">FAIR 分析</a>
                    <a href="#tprm-fourth-party">第四方风险</a>
                    <a href="#tprm-shadow-saas">影子 SaaS</a>

                    <h4>入驻与采购</h4>
                    <a href="#onboarding-workflow">供应商入驻</a>
                    <a href="#ai-review">AI 审查</a>
                    <a href="#procurement-cyber-status">采购网络安全状态</a>
                    <a href="#shadow-saas-grip">Grip 影子 SaaS 集成</a>
                    <a href="#shadow-saas-hero">Hero 影子 SaaS 集成</a>
                    <a href="#zscaler">Zscaler 封锁</a>

                    <h4>管理门户</h4>
                    <a href="#admin-general">常规设置</a>
                    <a href="#admin-branding">品牌与主题</a>
                    <a href="#admin-users">用户管理</a>
                    <a href="#admin-email">邮件配置</a>
                    <a href="#admin-saml">SAML / SSO</a>
                    <a href="#admin-ai">AI 集成</a>
                    <a href="#admin-backup">大型数据库备份</a>
                    <a href="#admin-updates">平台更新</a>

                    <h4>帮助与参考</h4>
                    <a href="#faq">常见问题</a>
                    <a href="#troubleshooting">故障排除</a>
                    <a href="#glossary">术语表</a>
                </nav>

