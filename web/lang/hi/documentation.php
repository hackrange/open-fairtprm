
                <!-- Print-only: Cover Page -->
                <div class="print-cover">
                    <img class="cover-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
                    <div class="cover-rule"></div>
                    <h1>प्लेटफ़ॉर्म दस्तावेज़ीकरण</h1>
                    <div class="cover-edition">Governance, Risk &amp; Compliance &bull; Third Party Risk Management</div>
                    <div class="cover-version">संस्करण 2.6.2</div>
                    <div class="cover-rule-bottom"></div>
                    <div class="cover-meta">
                        <strong>दिनांक:</strong> <?php echo date('F j, Y'); ?><br>
                        <strong>वर्गीकरण:</strong> केवल आंतरिक उपयोग<br>
                        <strong>तैयार किया गया:</strong> GRC प्रशासन टीम द्वारा
                    </div>
                </div>

                <!-- Print-only: Introduction & Purpose -->
                <div class="print-intro">
                    <h2>परिचय</h2>

                    <h3>उद्देश्य</h3>
                    <p>यह दस्तावेज़ Fair TPRM &amp; GRC Platform के लिए व्यापक दस्तावेज़ीकरण प्रदान करता है। यह तृतीय-पक्ष जोखिम प्रबंधन, गवर्नेंस, जोखिम मूल्यांकन और अनुपालन संचालन में शामिल सभी कर्मियों के लिए एक उपयोगकर्ता मार्गदर्शिका और संदर्भ मैनुअल दोनों के रूप में कार्य करता है।</p>
                    <p>इच्छित दर्शकों में GRC विश्लेषक, अनुपालन अधिकारी, लेखापरीक्षक, IT सुरक्षा कर्मचारी, खरीद टीमें और सिस्टम प्रशासक शामिल हैं। चाहे आप अपना पहला अनुपालन मूल्यांकन कर रहे हों या किसी चल रहे ऑडिट कार्यक्रम का प्रबंधन कर रहे हों, यह मार्गदर्शिका आपको आवश्यक चरण-दर-चरण निर्देश प्रदान करती है।</p>

                    <h3>दायरा</h3>
                    <p>यह दस्तावेज़ीकरण निम्नलिखित प्लेटफ़ॉर्म मॉड्यूल और क्षमताओं को कवर करता है:</p>
                    <ul>
                        <li><strong>GRC मॉड्यूल</strong> &mdash; एकाधिक फ्रेमवर्क (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, NIST 800-171) में एकीकृत अनुपालन मूल्यांकन, आंतरिक नियंत्रण प्रबंधन, साक्ष्य संग्रह, नीति जीवनचक्र प्रबंधन, ऑडिट प्रबंधन, जोखिम रजिस्टर, निरंतर निगरानी और परिपक्वता स्कोरिंग</li>
                        <li><strong>TPRM मॉड्यूल</strong> &mdash; तृतीय-पक्ष विक्रेता ऑनबोर्डिंग, जोखिम टीयरिंग, सुरक्षा मूल्यांकन, मात्रात्मक जोखिम विश्लेषण (FAIR), बाहरी सुरक्षा स्कोरिंग, चतुर्थ-पक्ष जोखिम ट्रैकिंग और शैडो SaaS खोज</li>
                        <li><strong>Admin Portal</strong> &mdash; सिस्टम कॉन्फ़िगरेशन, उपयोगकर्ता और समूह प्रबंधन, ब्रांडिंग, ईमेल सेटिंग, SSO/SAML एकीकरण और AI प्लेटफ़ॉर्म कॉन्फ़िगरेशन</li>
                    </ul>

                    <h3>इस मार्गदर्शिका का उपयोग कैसे करें</h3>
                    <p>यह मार्गदर्शिका चार भागों में व्यवस्थित है। <strong>भाग 1 (आरंभ करना)</strong> प्लेटफ़ॉर्म नेविगेशन, उपयोगकर्ता भूमिकाएं और आपके पहले लॉगिन को कवर करता है। <strong>भाग 2 (GRC मॉड्यूल)</strong> अनुपालन मूल्यांकन प्रक्रिया का विस्तृत विवरण प्रदान करता है, जो आपका पहला मूल्यांकन बनाने से शुरू होकर साक्ष्य संग्रह, स्कोरिंग और रिपोर्ट निर्माण तक जाता है। <strong>भाग 3 (TPRM मॉड्यूल)</strong> विक्रेता जोखिम प्रबंधन को कवर करता है। <strong>भाग 4 (Admin Portal)</strong> सिस्टम प्रशासन को कवर करता है।</p>
                    <p>यदि आप प्लेटफ़ॉर्म में नए हैं, तो <em>आरंभ करना</em> अनुभाग से शुरू करें और फिर पाँच-चरणीय GRC क्विक स्टार्ट गाइड का पालन करें। प्रत्येक चरण में सटीक, क्लिक-दर-क्लिक निर्देश शामिल हैं।</p>

                    <h3>दस्तावेज़ परंपराएं</h3>
                    <p>इस दस्तावेज़ में, निम्नलिखित परंपराओं का उपयोग किया गया है:</p>
                    <ul>
                        <li><strong>बोल्ड टेक्स्ट</strong> महत्वपूर्ण अवधारणाओं या जोर को इंगित करता है</li>
                        <li><code>Code formatting</code> उन मानों को इंगित करता है जो आप टाइप करते हैं या सिस्टम-जनित संदर्भ</li>
                        <li>क्रमांकित चरण सूचियाँ क्रमानुसार पालन की जाने वाली अनुक्रमिक प्रक्रियाओं को इंगित करती हैं</li>
                        <li>कॉलआउट बॉक्स सुझाव, चेतावनियाँ और महत्वपूर्ण संदर्भ प्रदान करते हैं</li>
                    </ul>
                </div>

                <!-- Print-only: Table of Contents (populated by JS) -->
                <div class="print-toc">
                    <h2>विषय-सूची</h2>
                    <div id="tocBody"></div>
                </div>

                <!-- Main content body -->
                <div class="doc-body">

                <h1>प्लेटफ़ॉर्म दस्तावेज़ीकरण</h1>
                <p class="doc-subtitle">Fair TPRM &amp; GRC Platform &mdash; Version 2.6.2 &mdash; अंतिम अपडेट: <?php echo date('F j, Y'); ?></p>

                <div style="margin-bottom:20px;">
                    <button class="btn-doc" id="printBtn">PDF डाउनलोड करें</button>
                </div>

<!-- old doc-toc removed — print-toc above now serves as the on-screen TOC -->


<!-- ================================================================
     GETTING STARTED
     ================================================================ -->
<div class="doc-section" id="overview">
    <h2>प्लेटफ़ॉर्म अवलोकन</h2>
    <p>यह प्लेटफ़ॉर्म आपके संगठन की सुरक्षा स्थिति प्रबंधित करने के लिए दो एकीकृत मॉड्यूल प्रदान करता है:</p>
    <ul>
        <li><strong>TPRM (Third Party Risk Management)</strong> &mdash; अपने विक्रेताओं और आपूर्तिकर्ताओं को ट्रैक करें, मूल्यांकन करें और स्कोर करें। समझें कि प्रत्येक विक्रेता आपके संगठन के लिए कितना सुरक्षा जोखिम उत्पन्न करता है।</li>
        <li><strong>GRC (Governance, Risk &amp; Compliance)</strong> &mdash; अनुपालन फ्रेमवर्क (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, और अधिक) प्रबंधित करें, एक एकल एकीकृत प्रश्नावली का उत्तर दें जो सभी फ्रेमवर्क को एक साथ कवर करती है, आंतरिक नियंत्रण ट्रैक करें, साक्ष्य अपलोड करें, नीतियाँ प्रबंधित करें और ऑडिट चलाएं।</li>
    </ul>
    <p>प्रशासकों के पास सिस्टम कॉन्फ़िगरेशन, उपयोगकर्ता प्रबंधन, एकीकरण और रखरखाव के लिए <strong>Admin Portal</strong> तक भी पहुंच है।</p>

    <div class="callout callout-success">
        <strong>मुख्य अवधारणा &mdash; एक मूल्यांकन, अनेक फ्रेमवर्क:</strong> GRC मॉड्यूल 14 सुरक्षा डोमेन में 146 प्रश्नों के साथ एक <em>एकीकृत मूल्यांकन प्रश्नावली</em> का उपयोग करता है। जब आप इन प्रश्नों का एक बार उत्तर देते हैं, तो प्लेटफ़ॉर्म स्वचालित रूप से प्रत्येक समर्थित फ्रेमवर्क (SOC 2, ISO 27001, PCI DSS, आदि) के विरुद्ध आपके अनुपालन प्रतिशत की गणना करता है &mdash; कोई डुप्लीकेट कार्य नहीं।
    </div>
</div>

<div class="doc-section" id="navigation">
    <h2>साइडबार नेविगेट करना</h2>
    <p>बायां साइडबार आपका प्राथमिक नेविगेशन टूल है। यह संकुचन योग्य मॉड्यूल और अनुभागों में व्यवस्थित है:</p>
    <ol class="steps">
        <li>साइडबार के शीर्ष पर आप अपनी कंपनी का लोगो और ब्रांडिंग टेक्स्ट देखते हैं।</li>
        <li>उसके नीचे दो संकुचन योग्य मॉड्यूल हेडर हैं: <span class="menu-label">TPRM Module</span> और <span class="menu-label">GRC Module</span>। किसी भी हेडर पर क्लिक करें उसे विस्तारित या संकुचित करने के लिए। आपका ब्राउज़र याद रखता है कि कौन से मॉड्यूल खुले हैं।</li>
        <li>प्रत्येक मॉड्यूल के अंदर, संकुचन योग्य <strong>अनुभाग</strong> हैं (जैसे, "Compliance", "Evidence &amp; Monitoring", "Assessment &amp; Audit")। किसी अनुभाग शीर्षक पर क्लिक करें उसे विस्तारित करने और अंदर के नेविगेशन लिंक देखने के लिए।</li>
        <li>साइडबार के नीचे आपको उपयोगिता लिंक मिलेंगे: <span class="menu-label">Dashboard</span>, <span class="menu-label">Profile</span>, <span class="menu-label">Documentation</span> (यह पृष्ठ), और <span class="menu-label">Administration</span> (केवल एडमिन)।</li>
    </ol>

    <h3>GRC मॉड्यूल साइडबार संरचना</h3>
    <p>जब आप <span class="menu-label">GRC Module</span> का विस्तार करते हैं, तो आप ये अनुभाग देखेंगे:</p>
    <table class="doc-table">
        <tr><th>अनुभाग</th><th>अंदर के पृष्ठ</th><th>इसमें क्या है</th></tr>
        <tr><td><strong>Compliance</strong></td><td>GRC Dashboard, Frameworks, Internal Controls, Framework Crosswalk</td><td>आपकी अनुपालन स्थिति अवलोकन, फ्रेमवर्क प्रबंधन, नियंत्रण लाइब्रेरी और क्रॉस-फ्रेमवर्क मैपिंग</td></tr>
        <tr><td><strong>Evidence &amp; Monitoring</strong></td><td>Evidence Library, Continuous Monitors</td><td>अनुपालन साक्ष्य अपलोड और प्रबंधित करें; स्वचालित अनुपालन जांच कॉन्फ़िगर करें</td></tr>
        <tr><td><strong>Policy Management</strong></td><td>Policies</td><td>संगठनात्मक नीतियाँ बनाएं, संस्करणबद्ध करें, अनुमोदित करें और प्रकाशित करें</td></tr>
        <tr><td><strong>Assessment &amp; Audit</strong></td><td>CSF Maturity Score, Assessment Questionnaire, Task Inbox, Audits, Findings, Risk Register</td><td>एकीकृत मूल्यांकन प्रश्नावली, परिपक्वता डैशबोर्ड, ऑडिट और जोखिम ट्रैकिंग</td></tr>
    </table>
</div>

<div class="doc-section" id="roles">
    <h2>उपयोगकर्ता भूमिकाएं &amp; अनुमतियाँ</h2>
    <p>उपयोगकर्ताओं को एक या अधिक <strong>ACL Groups</strong> में असाइन किया जाता है जो निर्धारित करते हैं कि वे क्या देख और कर सकते हैं। एक प्रशासक <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span> &rarr; <span class="btn-label">Groups</span> बटन के माध्यम से समूह असाइन करता है।</p>
    <table class="doc-table">
        <tr><th>समूह</th><th>आप क्या कर सकते हैं</th></tr>
        <tr><td><strong>Administrator</strong></td><td>सभी चीज़ों तक पूर्ण पहुंच &mdash; सभी मॉड्यूल, एडमिन सेटिंग, उपयोगकर्ता प्रबंधन और सिस्टम कॉन्फ़िगरेशन</td></tr>
        <tr><td><strong>Cyber TPRM</strong></td><td>TPRM मॉड्यूल तक पूर्ण पहुंच &mdash; विक्रेता बनाएं/संपादित करें/हटाएं, मूल्यांकन चलाएं, FAIR विश्लेषण, स्कोरिंग</td></tr>
        <tr><td><strong>Cyber GRC</strong></td><td>GRC मॉड्यूल तक पूर्ण पहुंच &mdash; फ्रेमवर्क प्रबंधित करें, मूल्यांकन चलाएं, साक्ष्य अपलोड करें, नीतियाँ प्रबंधित करें, ऑडिट चलाएं, जोखिम प्रबंधित करें</td></tr>
        <tr><td><strong>GRC Contributors</strong></td><td>सीमित GRC पहुंच &mdash; असाइन किए गए कार्य पूर्ण करें, साक्ष्य प्रदान करें, असाइन किए गए मूल्यांकन प्रश्नों के उत्तर दें</td></tr>
        <tr><td><strong>Auditor</strong></td><td>TPRM और GRC दोनों मॉड्यूल तक <strong>केवल-पढ़ने योग्य पहुंच</strong> &mdash; सब कुछ देख सकते हैं, साक्ष्य डाउनलोड कर सकते हैं और रिपोर्ट बना सकते हैं, लेकिन बना, संपादित या हटा नहीं सकते</td></tr>
        <tr><td><strong>Procurement</strong></td><td>विक्रेता ऑनबोर्डिंग अनुरोध बनाएं और प्रबंधित करें, विक्रेता दस्तावेज़ अपलोड करें</td></tr>
        <tr><td><strong>Stakeholder</strong></td><td>अपने स्वयं के विक्रेता अनुरोध देखें और उन्हें असाइन किए गए कार्यों पर प्रतिक्रिया दें</td></tr>
    </table>

    <div class="callout callout-info">
        <strong>साइडबार में GRC मॉड्यूल देखने के लिए:</strong> आपको <strong>Administrator</strong>, <strong>Cyber GRC</strong>, या <strong>Auditor</strong> समूह में होना चाहिए। यदि आप साइडबार में GRC Module नहीं देखते हैं, तो अपने प्रशासक से इनमें से किसी एक समूह में आपको जोड़ने के लिए कहें।
    </div>
</div>

<div class="doc-section" id="first-login">
    <h2>आपका पहला लॉगिन</h2>
    <ol class="steps">
        <li>अपना वेब ब्राउज़र खोलें और अपने प्लेटफ़ॉर्म URL पर जाएं (जैसे, <code>https://tprm.yourcompany.com</code>)।</li>
        <li>अपने प्रशासक द्वारा प्रदान किया गया <span class="field-label">Username</span> और <span class="field-label">Password</span> दर्ज करें।</li>
        <li>यदि आपके खाते के लिए दो-कारक प्रमाणीकरण (TOTP) सक्षम है, तो अपना प्रमाणीकरक ऐप (Google Authenticator, Microsoft Authenticator, आदि) खोलें और संकेत मिलने पर 6-अंकीय कोड दर्ज करें।</li>
        <li>आप <strong>Dashboard</strong> पर पहुंचेंगे। शीर्ष बार "Welcome, [Your Name]" दिखाता है जिसमें Admin (यदि आप प्रशासक हैं), Profile और Logout के लिंक हैं।</li>
        <li>बाएं साइडबार को देखें। यदि आप <strong>Cyber GRC</strong> या <strong>Administrator</strong> समूह में हैं, तो साइडबार में <span class="menu-label">GRC Module</span> दिखेगा। GRC नेविगेशन विस्तारित करने के लिए उस पर क्लिक करें।</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/dashboard.png" alt="The platform dashboard after logging in" loading="lazy">
        <figcaption><strong>डैशबोर्ड।</strong> साइन इन करने के बाद आप यहाँ पहुंचते हैं। शीर्ष बार (ऊपर दाईं ओर) में <strong>Admin</strong>, <strong>Profile</strong>, और <strong>Logout</strong> हैं। बायां साइडबार आपका मुख्य मेनू है।</figcaption>
    </figure>
</div>


<!-- ================================================================
     WHAT'S NEW IN 2.6.2
     ================================================================ -->
<div class="doc-section" id="whats-new">
    <h2>संस्करण 2.6.2 में क्या नया है</h2>
    <p>Version 2.6.2 <strong>विक्रेता ऑनबोर्डिंग, खरीद सहयोग, बहु-भाषा समर्थन और शैडो-SaaS खोज</strong> पर केंद्रित कई सुविधाएं जोड़ता है। यदि आपने पहले के संस्करण का उपयोग किया है, तो यहाँ बताया गया है कि क्या नया है। प्रत्येक आइटम इस गाइड में बाद में उसके पूर्ण विवरण से लिंक करता है।</p>
    <table class="doc-table">
        <tr><th>नई सुविधा</th><th>यह क्या करती है</th><th>किसके लिए है</th></tr>
        <tr><td><strong><a href="#language">भाषा सेटिंग</a></strong></td><td>प्लेटफ़ॉर्म को 8 भाषाओं में उपयोग करें। प्रत्येक व्यक्ति अपनी भाषा चुनता है; एडमिन तय करते हैं कि कौन सी भाषाएं उपलब्ध हैं।</td><td>सभी</td></tr>
        <tr><td><strong><a href="#onboarding-workflow">Procurement Onboarding &amp; Vendor ID</a></strong></td><td>साइबर समीक्षा के लिए सबमिट करने से पहले एक विक्रेता को खरीद के माध्यम से ऑनबोर्ड किया जाना चाहिए और उसके पास एक वैध Vendor ID (VID) होनी चाहिए।</td><td>Procurement, Stakeholders</td></tr>
        <tr><td><strong><a href="#ai-review">विक्रेताओं के लिए AI Review</a></strong></td><td>उन विक्रेताओं के लिए एक समर्पित समीक्षा स्थिति जिनकी सेवाएं AI का उपयोग करती हैं, साथ ही "Force AI Review" क्रिया।</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#procurement-cyber-status">Procurement Cyber Status</a></strong></td><td>समीक्षाधीन विक्रेताओं को दिखाने वाला एक लाइव पृष्ठ, साइबर टीम द्वारा खरीद के साथ साझा किए गए अपडेट का चल रहा इतिहास, और एक साप्ताहिक ईमेल डाइजेस्ट।</td><td>Procurement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#shadow-saas-grip">Grip Shadow SaaS एकीकरण</a></strong></td><td>आपके संगठन में उपयोग किए जाने वाले SaaS ऐप्स स्वचालित रूप से खोजें और उन्हें Shadow SaaS सूची में लाएं।</td><td>Admins</td></tr>
        <tr><td><strong><a href="#shadow-saas-hero">Hero Shadow SaaS एकीकरण</a></strong></td><td>एक वैकल्पिक Shadow SaaS प्रदाता: HERO Security से विक्रेता और सुरक्षा समस्याएं खोजें और उन्हें उसी Shadow SaaS सूची में फीड करें। Grip और Hero परस्पर अनन्य हैं &mdash; एक या दूसरे का उपयोग करें।</td><td>Admins</td></tr>
        <tr><td><strong><a href="#zscaler">Zscaler ब्लॉकिंग</a></strong></td><td>एक क्लिक से Zscaler में किसी अनुमोदित ऐप के वेब डोमेन को सीधे ब्लॉक करें।</td><td>Admins</td></tr>
        <tr><td><strong><a href="#admin-updates">इन-ऐप अपग्रेड</a></strong></td><td>अपनी रजिस्ट्री में नए संस्करण की जांच करें और Admin Portal के अंदर से अपग्रेड करें।</td><td>Admins</td></tr>
        <tr><td><strong><a href="#question-types">Phone &amp; VAT प्रश्न प्रकार</a></strong></td><td>नए मूल्यांकन/ऑनबोर्डिंग फ़ील्ड प्रकार: एक फ़ोन नंबर जिसमें देश-कोड &amp; फ्लैग पिकर (स्वतः-स्वरूपित) है, और एक EU VAT नंबर जिसमें डबल एंट्री और आधिकारिक EU VIES सेवा के विरुद्ध मुफ्त लाइव सत्यापन है।</td><td>सभी</td></tr>
        <tr><td><strong><a href="#question-types">विक्रेता डेटा &amp; खोज सुधार</a></strong></td><td>प्रत्येक विक्रेता पर एक VAT नंबर स्टोर करें (विक्रेता पृष्ठ पर "Add VAT" शॉर्टकट के साथ दिखाया गया), क्विक सर्च में VAT नंबर से विक्रेता खोजें, और एक स्पष्ट Procurement-Onboarding स्कोरिंग बैनर।</td><td>Procurement, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#admin-backup">बड़े डेटाबेस बैकअप</a></strong></td><td>बैकअप और रिस्टोर अब बिना टाइमआउट के मल्टी-गिगाबाइट डेटाबेस और बहुत बड़े रिकॉर्ड का समर्थन करते हैं।</td><td>Admins</td></tr>
        <tr><td><strong><a href="#assessment-forms">मूल्यांकन फ़ॉर्म &amp; AI ऑटो-फ़िल</a></strong></td><td>एक मूल्यांकन को भरने योग्य PDF या Excel वर्कबुक के रूप में डाउनलोड करें, एक पूर्ण की गई फ़ाइल वापस आयात करें, और &mdash; एक AI प्रदाता के साथ &mdash; विक्रेता के वर्तमान प्रमाणपत्रों से उत्तर स्वतः-भरें।</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#tprm-action-plan">Vendor Action Plan</a></strong></td><td>नियत तिथियों, स्वामियों, ईमेल अलर्ट और स्थिति नोट के साथ किसी विक्रेता के विरुद्ध अनुवर्ती क्रियाएं शेड्यूल करें (संपर्क करें, मूल्यांकन भेजें, वार्षिक समीक्षा बाध्य करें)।</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#custom-onboarding">कस्टम ऑनबोर्डिंग फ़ील्ड &amp; Custom Data</a></strong></td><td>प्रति-भूमिका दृश्यता के साथ किसी विक्रेता पर अतिरिक्त, संगठन-विशिष्ट फ़ील्ड कैप्चर करें, उन्हें Custom Data टैब पर संपादित करें, और उन्हें CSV निर्यात और API में पढ़ें।</td><td>Admins, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#breach-alerts">Breach / Cyber Alerts</a></strong></td><td>एक आपूर्ति-श्रृंखला उल्लंघन फ़ीड (Grip घटनाओं सहित) जिसमें प्रभावित-उपयोगकर्ता ड्रिल-डाउन और बल्क acknowledge / false-positive / delete है।</td><td>Cyber TPRM, Admins</td></tr>
        <tr><td><strong><a href="#admin-acl-groups">कस्टम एक्सेस-कंट्रोल समूह</a></strong></td><td>अपने स्वयं के ACL समूह बनाएं, किसी मौजूदा समूह से अनुमतियाँ क्लोन करें, और प्रति मॉड्यूल Read बनाम Read/Write सेट करें। शिप किए गए समूह सुरक्षित हैं।</td><td>Admins</td></tr>
        <tr><td><strong><a href="#admin-templates">Assessment Template Builder</a></strong></td><td>नए प्रश्न प्रकार (multi-select, phone, VAT), टेम्पलेट-संचालित प्रमाणपत्र निर्देश, प्रति-भूमिका फ़ील्ड गेटिंग, और निष्क्रिय टेम्पलेट डिफ़ॉल्ट रूप से छुपाए गए।</td><td>Admins, Cyber TPRM</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>मैं कैसे जानूं कि मैं किस संस्करण पर हूं?</strong> प्रशासक इंस्टॉल किए गए संस्करण को देखने के लिए <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span> पर जा सकते हैं। यह गाइड <strong>v2.6.2</strong> का वर्णन करती है। देखें <a href="#admin-updates">प्लेटफ़ॉर्म अपडेट करना</a>।
    </div>
</div>

<div class="doc-section" id="language">
    <h2>अपनी भाषा बदलना <span class="new-badge">2.6.2 में नया</span></h2>
    <p>प्लेटफ़ॉर्म इंटरफ़ेस <strong>8 भाषाओं</strong> में प्रदर्शित किया जा सकता है। प्रत्येक व्यक्ति अपनी भाषा चुनता है &mdash; इसे बदलने से केवल <em>आपकी</em> स्क्रीन प्रभावित होती है, किसी और की नहीं। आपकी पसंद हर बार लॉग इन करने पर याद रखी जाती है।</p>

    <h3>उपलब्ध भाषाएं</h3>
    <table class="doc-table">
        <tr><th>भाषा</th><th>मेनू में दिखाया गया</th></tr>
        <tr><td>English</td><td>English</td></tr>
        <tr><td>Spanish</td><td>Espa&ntilde;ol</td></tr>
        <tr><td>Italian</td><td>Italiano</td></tr>
        <tr><td>Ukrainian</td><td>&#1059;&#1082;&#1088;&#1072;&#1111;&#1085;&#1089;&#1100;&#1082;&#1072;</td></tr>
        <tr><td>Chinese (Simplified)</td><td>&#20013;&#25991;&#65288;&#31616;&#20307;&#65289;</td></tr>
        <tr><td>Hindi</td><td>&#2361;&#2367;&#2344;&#2381;&#2342;&#2368;</td></tr>
        <tr><td>French</td><td>Fran&ccedil;ais</td></tr>
        <tr><td>Portuguese</td><td>Portugu&ecirc;s</td></tr>
    </table>
    <p class="callout callout-info" style="margin-top:0;"><strong>केवल वे भाषाएं जो आपके प्रशासक ने चालू की हैं, आपकी सूची में दिखाई देंगी।</strong> English हमेशा उपलब्ध है और इसे बंद नहीं किया जा सकता।</p>

    <h3>अपनी भाषा कैसे बदलें (चरण दर चरण)</h3>
    <ol class="steps">
        <li>किसी भी पृष्ठ के ऊपरी-दाएं कोने में <span class="menu-label">Profile</span> पर क्लिक करें।</li>
        <li>Profile पृष्ठ पर, <span class="field-label">Language Preference</span> कार्ड तक स्क्रॉल करें।</li>
        <li><span class="field-label">Language</span> ड्रॉप-डाउन पर क्लिक करें और अपनी भाषा चुनें। उस भाषा पर वापस जाने के लिए जो आपके प्रशासक ने सभी के लिए सेट की है, <strong>System default</strong> चुनें।</li>
        <li><span class="btn-label">Update Language</span> पर क्लिक करें। पृष्ठ रीलोड होता है और मेनू, बटन और लेबल अब आपकी चुनी हुई भाषा में दिखाई देते हैं।</li>
    </ol>
    <figure class="doc-figure narrow">
        <img src="app/docs/profile-language-card.png" alt="Language Preference card on the Profile page" loading="lazy">
        <figcaption><strong>Profile &rarr; Language Preference.</strong> एक भाषा चुनें और <strong>Update Language</strong> पर क्लिक करें। <em>System default</em> चुनने से आपकी व्यक्तिगत पसंद हट जाती है।</figcaption>
    </figure>

    <h3>प्रशासकों के लिए: कौन सी भाषाएं उपलब्ध हैं, यह चुनना</h3>
    <p>प्रशासक <strong>डिफ़ॉल्ट भाषा</strong> (नए उपयोगकर्ताओं के लिए और किसी के लॉग इन करने से पहले साइन-इन पृष्ठ के लिए उपयोग की जाती है) और कौन सी भाषाएं सभी को चुनने की अनुमति है, यह तय करते हैं।</p>
    <ol class="steps">
        <li><span class="menu-label">Admin</span> &rarr; <span class="menu-label">General</span> पर जाएं।</li>
        <li><span class="field-label">Default Language</span> ड्रॉप-डाउन खोजें और संगठन-व्यापी डिफ़ॉल्ट चुनें।</li>
        <li><span class="field-label">Enabled Languages</span> के अंतर्गत, उन भाषाओं को टिक करें जिन्हें आप उपलब्ध कराना चाहते हैं। (English हमेशा टिक किया हुआ है और इसे अक्षम नहीं किया जा सकता।)</li>
        <li><span class="btn-label">Save Configuration</span> पर क्लिक करें।</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-general-language.png" alt="Default Language and Enabled Languages settings in Admin General" loading="lazy">
        <figcaption><strong>Admin &rarr; General.</strong> <strong>Default Language</strong> सेट करें और <strong>Enabled Languages</strong> टिक करें जिनमें से उपयोगकर्ता चुन सकते हैं।</figcaption>
    </figure>

    <div class="callout callout-warning">
        <strong>जानने योग्य बात:</strong> इंटरफ़ेस वहाँ अनुवादित होता है जहाँ आपकी भाषा के लिए अनुवाद मौजूद है; एक स्ट्रिंग जिसका अभी तक अनुवाद नहीं हुआ है वह English पर वापस चली जाती है, इसलिए आपको कभी-कभी कोई English लेबल दिख सकता है। जो सामग्री आप या आपके विक्रेता टाइप करते हैं (विक्रेता नाम, नोट, अपलोड की गई फ़ाइल के नाम, मुक्त-पाठ उत्तर) वह हमेशा ठीक वैसे ही दिखाई जाती है जैसे दर्ज की गई थी। विक्रेता मूल्यांकन <em>प्रश्न</em> प्रदर्शन के लिए स्वचालित रूप से अनुवादित हो सकते हैं जब एक AI प्रदाता कॉन्फ़िगर किया गया हो (देखें <a href="#admin-ai">AI Integration</a>); उसके बिना वे उसी भाषा में रहते हैं जिसमें वे लिखे गए थे। संग्रहीत उत्तर मान हमेशा English में रहते हैं ताकि सभी भाषाओं में स्कोरिंग और रिपोर्ट सुसंगत बनी रहें।
    </div>
</div>


<!-- ================================================================
     PHONE & VAT QUESTION TYPES + VENDOR DATA IMPROVEMENTS
     ================================================================ -->
<div class="doc-section" id="question-types">
    <h2>Phone &amp; VAT प्रश्न प्रकार <span class="new-badge">2.6.2 में नया</span></h2>
    <p><strong>Template Builder</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Assessment Templates</span>) में दो नए प्रश्न प्रकार जोड़े गए हैं जो संपर्क और कर विवरण को एक स्वच्छ, सुसंगत प्रारूप में कैप्चर करते हैं। इन्हें किसी भी मूल्यांकन या ऑनबोर्डिंग टेम्पलेट पर उपयोग किया जा सकता है, और अन्य प्रश्नों की तरह इन्हें एक विक्रेता फ़ील्ड से मैप किया जा सकता है ताकि उत्तर विक्रेता रिकॉर्ड पर जाए।</p>

    <h3>Phone</h3>
    <p><strong>Phone</strong> प्रकार नंबर बॉक्स के बगल में एक फ्लैग और डायलिंग कोड के साथ एक देश चयनकर्ता दिखाता है। United States पहले सूचीबद्ध है; बाकी सभी देश वर्णानुक्रम में आते हैं। व्यक्ति जो भी प्रारूप टाइप करे &mdash; <code>314-444-5544</code>, <code>(314)&nbsp;444-5544</code> या <code>3144445544</code> &mdash; नंबर एक समान अंतरराष्ट्रीय प्रारूप में संग्रहीत किया जाता है (उदाहरण के लिए, US ध्वज चुनकर <code>3144445544</code> टाइप करने पर <code>+13144445544</code> स्टोर होता है)। डिफ़ॉल्ट Vendor Onboarding Request फ़ॉर्म अब प्राथमिक संपर्क के फ़ोन नंबर के लिए इस प्रकार का उपयोग करता है, और मूल्यांकन <strong>attestation</strong> फ़ोन फ़ील्ड भी इसका उपयोग करती है।</p>

    <h3>VAT (EU VAT नंबर)</h3>
    <p><strong>VAT</strong> प्रकार European VAT नंबर के लिए है। टाइपो से बचाने के लिए इसे <strong>दो बार दर्ज</strong> करना होगा, और सहेजने से पहले दोनों प्रविष्टियाँ मेल खानी चाहिए। नंबर एक सुसंगत रूप में संग्रहीत किया जाता है (अपरकेस, बिना रिक्त स्थान या विराम चिह्न &mdash; उदाहरण के लिए <code>DE123456789</code>)।</p>
    <ul>
        <li><strong>मुफ्त लाइव सत्यापन।</strong> जब आप टाइप करना समाप्त करते हैं, तो प्लेटफ़ॉर्म आधिकारिक <strong>EU VIES</strong> सेवा (यूरोपीय आयोग की VAT Information Exchange System) के विरुद्ध नंबर की जांच करता है। VIES मुफ्त है, किसी खाते की आवश्यकता नहीं है, और प्रत्येक सदस्य राज्य की लाइव रजिस्ट्री को दर्शाता है।</li>
        <li><strong>सलाहकार, कभी ब्लॉक करने वाला नहीं।</strong> यदि VIES नंबर की पुष्टि नहीं कर सकता, तो भी इसे सहेजा जाता है &mdash; एक सूचना बस आपको इसे दोबारा जांचने के लिए कहती है। यदि VIES क्षणिक रूप से धीमा है या किसी देश की रजिस्ट्री अस्थायी रूप से अनुपलब्ध है, तो नंबर सहेजा जाता है और आपको बाद में इसे सत्यापित करने के लिए कहा जाता है।</li>
        <li><strong>मांग पर विवरण।</strong> जब VIES किसी नंबर की पुष्टि करता है, तो उसके बगल में एक सूचना (&#9432;) बटन दिखाई देता है। इस पर क्लिक करने से VIES द्वारा लौटाए गए पंजीकृत कंपनी नाम और पता दिखाने वाला एक पैनल खुलता है।</li>
    </ul>
    <div class="callout callout-info">
        <strong>VAT को विक्रेता रिकॉर्ड से मैप करना।</strong> एक समर्पित <code>vat_number</code> फ़ील्ड उपलब्ध है, इसलिए उससे मैप किया गया VAT प्रश्न विक्रेता पर मान संग्रहीत करता है। जब आप Template Builder में VAT प्रश्न प्रकार चुनते हैं, तो यह मैपिंग आपके लिए स्वचालित रूप से चुनी जाती है।
    </div>

    <h3>विक्रेता पृष्ठ पर VAT</h3>
    <p>विक्रेता का VAT नंबर विक्रेता ऑनबोर्डिंग पृष्ठ पर <strong>Vendor Information</strong> कार्ड में दिखाया गया है। यदि कोई VAT फ़ाइल पर नहीं है, तो एक <strong>&ldquo;+ Add VAT&rdquo;</strong> बटन दिखाई देता है जो सीधे VAT फ़ील्ड पर फोकस के साथ संपादन मोड में जाता है।</p>

    <h3>VAT नंबर से विक्रेता खोजना</h3>
    <p>प्लेटफ़ॉर्म के ऊपरी-दाएं में <strong>quick search</strong> बॉक्स अब विक्रेता नाम, डोमेन और स्टेकहोल्डर के साथ-साथ VAT नंबर पर भी मेल खाता है। किसी विक्रेता के अपने नाम, डोमेन या VAT नंबर पर सीधे मेल हमेशा पहले दिखाए जाते हैं।</p>

    <h2>Procurement Onboarding स्कोरिंग बैनर <span class="new-badge">2.6.2 में नया</span></h2>
    <p>जब किसी विक्रेता की <strong>Procurement Onboarding</strong> स्थिति <strong>No</strong> पर सेट होती है, तो एक बैनर अब स्पष्ट करता है कि <em>जब तक विक्रेता Procurement Onboarding पूरा नहीं करता, स्वचालित विक्रेता स्कोरिंग अक्षम है</em>। यह विक्रेता ऑनबोर्डिंग पृष्ठ और संबंधित मूल्यांकन प्रश्न के नीचे दोनों जगह दिखाई देता है, और उत्तर बदलने के साथ तुरंत अपडेट होता है।</p>

    <h2>मूल्यांकन सबमिट करना: पहले आवश्यक फ़ील्ड जांची जाती हैं <span class="new-badge">2.6.2 में नया</span></h2>
    <p>जब कोई विक्रेता मूल्यांकन पर <strong>Submit</strong> पर क्लिक करता है, तो प्लेटफ़ॉर्म अब जमाकर्ता के attestation विवरण मांगने से <em>पहले</em> यह जांचता है कि हर आवश्यक प्रश्न का उत्तर दिया गया है। पहले एक गायब उत्तर केवल attestation भरने के बाद रिपोर्ट किया जाता था, जिससे उसे दोबारा भरना पड़ता था।</p>
</div>

<!-- ================================================================
     LARGE DATABASE BACKUPS
     ================================================================ -->
<div class="doc-section" id="admin-backup">
    <h2>बड़े डेटाबेस बैकअप &amp; रिस्टोर <span class="new-badge">2.6.2 में नया</span></h2>
    <p>बैकअप और रिस्टोर (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Backup</span>) अब टाइमआउट या मेमोरी समाप्त हुए बिना <strong>मल्टी-गिगाबाइट डेटाबेस</strong> और <strong>1&nbsp;GB</strong> के करीब पहुंचने वाले व्यक्तिगत रिकॉर्ड को संभालते हैं। पर्दे के पीछे डेटाबेस पैकेट सीमा, नेटवर्क टाइमआउट, अपलोड आकार और अनुरोध समय सीमाएं सभी को बहुत बड़े डेटा को समायोजित करने के लिए बढ़ाया गया था।</p>
    <div class="callout callout-info">
        <strong>बहुत बड़े डेटाबेस के लिए:</strong> मल्टी-गिगाबाइट फ़ाइल का बैकअप या रिस्टोर कुछ समय ले सकता है &mdash; जब तक यह समाप्त न हो, पृष्ठ खुला रखें। अत्यधिक बड़े डेटासेट (दसियों गिगाबाइट) सर्वर कमांड लाइन से रिस्टोर करना सबसे अच्छा है।
    </div>
</div>


<!-- ================================================================
     GRC MODULE - COMPREHENSIVE DOCUMENTATION
     ================================================================ -->
<div class="doc-section" id="grc-overview">
    <h2>GRC मॉड्यूल: Governance, Risk &amp; Compliance क्या है?</h2>
    <p><strong>GRC</strong> का अर्थ है <strong>Governance, Risk, and Compliance</strong>। यह यह सुनिश्चित करने की प्रथा है कि आपका संगठन नियामक आवश्यकताओं को पूरा करता है, सुरक्षा सर्वोत्तम प्रथाओं का पालन करता है, जोखिम प्रबंधित करता है और लेखापरीक्षकों और नियामकों को अनुपालन साबित कर सकता है।</p>

    <p>GRC मॉड्यूल आपकी मदद करता है:</p>
    <ul>
        <li><strong>अपनी सुरक्षा परिपक्वता का मूल्यांकन करें</strong> एक एकल एकीकृत प्रश्नावली का उपयोग करके जो एक साथ एकाधिक अनुपालन फ्रेमवर्क से मैप होती है</li>
        <li>SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, और अधिक के विरुद्ध <strong>अनुपालन ट्रैक करें</strong></li>
        <li><strong>आंतरिक नियंत्रण प्रबंधित करें</strong> &mdash; उन सुरक्षा उपायों को दस्तावेज़ीकृत करें जो आपके संगठन ने लागू किए हैं</li>
        <li><strong>साक्ष्य एकत्र और संग्रहीत करें</strong> &mdash; स्क्रीनशॉट, कॉन्फ़िगरेशन निर्यात, नीति दस्तावेज़ और प्रमाणपत्र अपलोड करें जो अनुपालन साबित करते हैं</li>
        <li><strong>नीतियाँ प्रबंधित करें</strong> &mdash; संगठनात्मक सुरक्षा नीतियाँ बनाएं, संस्करणबद्ध करें, अनुमोदित करें और प्रकाशित करें</li>
        <li><strong>ऑडिट चलाएं</strong> &mdash; ऑडिट योजना बनाएं, निष्कर्ष रिकॉर्ड करें, उपचार असाइन करें और बंद करने को ट्रैक करें</li>
        <li><strong>जोखिम ट्रैक करें</strong> &mdash; संभावना/प्रभाव स्कोरिंग और उपचार योजनाओं के साथ एक जोखिम रजिस्टर बनाए रखें</li>
        <li><strong>निरंतर निगरानी करें</strong> &mdash; एक शेड्यूल पर अनुपालन नियंत्रण सत्यापित करने वाली स्वचालित जांच सेट करें</li>
    </ul>

    <div class="callout callout-warning">
        <strong>महत्वपूर्ण अवधारणा &mdash; एकीकृत प्रश्न:</strong> प्लेटफ़ॉर्म में <strong>14 सुरक्षा डोमेन</strong> (Governance, Identity &amp; Access Management, Data Security, Network Security, आदि) में व्यवस्थित <strong>146 एकीकृत सुरक्षा प्रश्न</strong> हैं। प्रत्येक प्रश्न एकाधिक अनुपालन फ्रेमवर्क में विशिष्ट आवश्यकताओं के लिए पूर्व-मैप है। जब आप एक बार प्रश्न का उत्तर देते हैं, तो उत्तर स्वचालित रूप से उस प्रश्न से मैप किए गए हर फ्रेमवर्क पर लागू होता है। यह SOC 2, ISO 27001, और PCI DSS के लिए अलग से एक ही प्रश्न का उत्तर देने की आवश्यकता को समाप्त करता है।
    </div>
</div>


<div class="doc-section" id="grc-getting-started">
    <h2>GRC के साथ शुरुआत &mdash; त्वरित प्रारंभ मार्गदर्शिका</h2>
    <p>यदि आप GRC मॉड्यूल में बिल्कुल नए हैं, तो इन चरणों का क्रम में पालन करें। अंत तक, आपके पास सभी फ्रेमवर्क में स्कोर के साथ एक पूर्ण अनुपालन मूल्यांकन होगा।</p>

    <div class="callout callout-info">
        <strong>पूर्वापेक्षाएं:</strong><br>
        &bull; आपको <strong>Administrator</strong> या <strong>Cyber GRC</strong> समूह में एक उपयोगकर्ता के रूप में लॉग इन होना चाहिए<br>
        &bull; आपको बाएं साइडबार में <span class="menu-label">GRC Module</span> देखने में सक्षम होना चाहिए<br>
        &bull; यदि आप इसे नहीं देखते हैं, तो अपने प्रशासक से आपको Cyber GRC समूह में असाइन करने के लिए कहें (Admin &rarr; Users &rarr; अपने नाम के बगल में Groups बटन पर क्लिक करें &rarr; "Cyber GRC" चेक करें &rarr; Save)
    </div>

    <p>अनुशंसित वर्कफ़्लो है:</p>
    <ol>
        <li><strong>एक मूल्यांकन बनाएं</strong> &mdash; यह आपकी अनुपालन समीक्षा का दायरा और उद्देश्य परिभाषित करता है</li>
        <li><strong>प्रश्नों के उत्तर दें</strong> &mdash; 146 एकीकृत प्रश्नों के माध्यम से काम करें, प्रत्येक के लिए अपनी परिपक्वता स्तर रेट करें</li>
        <li><strong>साक्ष्य अपलोड करें</strong> &mdash; दस्तावेज़, स्क्रीनशॉट और फ़ाइलें संलग्न करें जो आपके उत्तर साबित करती हैं</li>
        <li><strong>अपने स्कोर देखें</strong> &mdash; Frameworks पृष्ठ पर अपने अनुपालन प्रतिशत जांचें</li>
        <li><strong>रिपोर्ट बनाएं</strong> &mdash; लेखापरीक्षकों के लिए विस्तृत प्रति-फ्रेमवर्क अनुपालन रिपोर्ट बनाएं</li>
    </ol>
    <p>प्रत्येक चरण नीचे विस्तार से समझाया गया है।</p>
</div>


<div class="doc-section" id="grc-step1">
    <h2>चरण 1: अपना पहला मूल्यांकन बनाएं</h2>
    <p>एक <strong>Assessment</strong> आपके संगठन की एक अनुपालन समीक्षा है। यह एक समय-बिंदु मूल्यांकन का प्रतिनिधित्व करता है जहां आप सुरक्षा प्रश्नों के उत्तर देते हैं, परिपक्वता रेटिंग रिकॉर्ड करते हैं और साक्ष्य एकत्र करते हैं। इसे एक "अनुपालन स्नैपशॉट" के रूप में सोचें।</p>

    <h3>नया मूल्यांकन कैसे बनाएं</h3>
    <ol class="steps">
        <li>बाएं साइडबार में, इसे विस्तारित करने के लिए <span class="menu-label">GRC Module</span> पर क्लिक करें।</li>
        <li>इसे विस्तारित करने के लिए <span class="menu-label">Assessment &amp; Audit</span> अनुभाग पर क्लिक करें।</li>
        <li><span class="menu-label">Assessment Questionnaire</span> पर क्लिक करें। यह मुख्य मूल्यांकन पृष्ठ खोलता है।</li>
        <li>पृष्ठ के शीर्ष पर, आपको एक <span class="btn-label">+ New Assessment</span> बटन दिखाई देगा। उस पर क्लिक करें।</li>
        <li>एक फ़ॉर्म दिखाई देगा। निम्नलिखित फ़ील्ड भरें:
            <ul>
                <li><span class="field-label">Title</span> &mdash; अपने मूल्यांकन को एक वर्णनात्मक नाम दें। उदाहरण: <code>2026 Annual Security Assessment - ACME Corp</code></li>
                <li><span class="field-label">Assessment Type</span> &mdash; मूल्यांकन का प्रकार चुनें:
                    <ul>
                        <li><strong>Initial</strong> &mdash; आपका पहला-कभी मूल्यांकन (नए उपयोगकर्ताओं के लिए अनुशंसित)</li>
                        <li><strong>Periodic</strong> &mdash; एक नियमित आवर्ती मूल्यांकन (जैसे, वार्षिक समीक्षा)</li>
                        <li><strong>Targeted</strong> &mdash; किसी विशिष्ट क्षेत्र पर केंद्रित मूल्यांकन</li>
                        <li><strong>Pre-Audit</strong> &mdash; एक औपचारिक ऑडिट से पहले तैयारी</li>
                        <li><strong>Certification</strong> &mdash; प्रमाणन उद्देश्यों के लिए मूल्यांकन (जैसे, SOC 2 Type II)</li>
                    </ul>
                </li>
                <li><span class="field-label">Scope</span> &mdash; संगठनात्मक दायरा चुनें या वर्णन करें। यह परिभाषित करता है कि आपके संगठन का कौन सा हिस्सा मूल्यांकन किया जा रहा है (जैसे, "All IT systems" या "Cloud Infrastructure")।</li>
                <li><span class="field-label">Lead Auditor</span> &mdash; इस मूल्यांकन का नेतृत्व करने वाले व्यक्ति को चुनें। ड्रॉपडाउन केवल Administrator या Cyber GRC समूहों के उपयोगकर्ता दिखाता है।</li>
                <li><span class="field-label">Planned Start Date</span> &mdash; जब आप मूल्यांकन शुरू करने की योजना बनाते हैं।</li>
                <li><span class="field-label">Planned End Date</span> &mdash; आपकी लक्ष्य पूर्णता तिथि।</li>
            </ul>
        </li>
        <li><span class="btn-label">Create Assessment</span> पर क्लिक करें।</li>
        <li>आपका नया मूल्यांकन <span class="status-label">Draft</span> स्थिति में बनाया गया है। आप अब प्रश्नों के उत्तर देना शुरू कर सकते हैं।</li>
    </ol>

    <div class="example-box">
        <strong>उदाहरण:</strong> आप अपने संगठन की पहली वार्षिक सुरक्षा समीक्षा आयोजित कर रहे हैं।<br><br>
        &bull; Title: <code>2026 Annual Security Assessment</code><br>
        &bull; Type: <code>Initial</code><br>
        &bull; Scope: <code>All Corporate IT Systems</code><br>
        &bull; Lead Auditor: <code>Jane Smith</code><br>
        &bull; Start Date: <code>March 1, 2026</code><br>
        &bull; End Date: <code>April 30, 2026</code>
    </div>

    <h3>मूल्यांकन स्थितियाँ</h3>
    <table class="doc-table">
        <tr><th>स्थिति</th><th>अर्थ</th></tr>
        <tr><td><span class="status-label">Draft</span></td><td>मूल्यांकन बनाया गया है लेकिन काम अभी शुरू नहीं हुआ है। प्रश्नों के उत्तर दिए जा सकते हैं।</td></tr>
        <tr><td><span class="status-label">In Progress</span></td><td>सक्रिय मूल्यांकन &mdash; टीम के सदस्य प्रश्नों के उत्तर दे रहे हैं और साक्ष्य अपलोड कर रहे हैं।</td></tr>
        <tr><td><span class="status-label">Under Review</span></td><td>सभी प्रश्नों के उत्तर दिए गए &mdash; एक प्रमुख लेखापरीक्षक या सत्यापनकर्ता प्रतिक्रियाओं की समीक्षा कर रहा है।</td></tr>
        <tr><td><span class="status-label">Completed</span></td><td>मूल्यांकन समाप्त और अंतिम रूप दिया गया है। प्रतिक्रियाएं लॉक हैं।</td></tr>
        <tr><td><span class="status-label">Archived</span></td><td>रिकॉर्ड के लिए रखा गया ऐतिहासिक मूल्यांकन। अब सक्रिय नहीं।</td></tr>
    </table>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment.png" alt="Assessment list on the Assessment Questionnaire page" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Assessment Questionnaire.</strong> प्रत्येक मूल्यांकन अपने संदर्भ, शीर्षक, प्रकार, स्थिति, प्रमुख लेखापरीक्षक, वर्तमान CSF स्कोर और अनुपालन %, और नियोजित तिथि के साथ सूचीबद्ध है। एक शुरू करने के लिए <span class="btn-label">+ New Assessment</span> का उपयोग करें, या किसी मौजूदा को जारी रखने के लिए <span class="btn-label">Open</span> का उपयोग करें। शीर्ष पर स्थिति टैब सूची को फ़िल्टर करते हैं।</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step2">
    <h2>चरण 2: मूल्यांकन प्रश्नों के उत्तर दें</h2>
    <p>एक बार जब आप मूल्यांकन बना लेते हैं, तो आपको 146 एकीकृत सुरक्षा प्रश्नों के उत्तर देने होंगे। प्रत्येक प्रश्न 14 सुरक्षा डोमेन में से एक से संबंधित है।</p>

    <h3>14 सुरक्षा डोमेन</h3>
    <table class="doc-table">
        <tr><th>कोड</th><th>डोमेन नाम</th><th>प्रश्न</th><th>यह क्या कवर करता है</th></tr>
        <tr><td><code>GOV</code></td><td>Governance &amp; Leadership</td><td>12</td><td>सुरक्षा कार्यक्रम नेतृत्व, रणनीति, बजट, बोर्ड रिपोर्टिंग</td></tr>
        <tr><td><code>IAM</code></td><td>Identity &amp; Access Management</td><td>14</td><td>उपयोगकर्ता खाते, प्रमाणीकरण, पहुंच नियंत्रण, विशेषाधिकृत पहुंच</td></tr>
        <tr><td><code>DSP</code></td><td>Data Security &amp; Privacy</td><td>12</td><td>डेटा वर्गीकरण, एन्क्रिप्शन, गोपनीयता, डेटा हानि निवारण</td></tr>
        <tr><td><code>EPS</code></td><td>Endpoint &amp; Platform Security</td><td>10</td><td>लैपटॉप, सर्वर, मोबाइल डिवाइस, पैचिंग, EDR</td></tr>
        <tr><td><code>NET</code></td><td>Network Security</td><td>11</td><td>फ़ायरवॉल, सेगमेंटेशन, VPN, DNS सुरक्षा, Wi-Fi</td></tr>
        <tr><td><code>APS</code></td><td>Application Security</td><td>10</td><td>सुरक्षित विकास, कोड समीक्षा, API सुरक्षा, WAF</td></tr>
        <tr><td><code>OPS</code></td><td>Security Operations</td><td>12</td><td>SIEM, लॉगिंग, निगरानी, भेद्यता स्कैनिंग, SOC</td></tr>
        <tr><td><code>INC</code></td><td>Incident Management</td><td>10</td><td>घटना प्रतिक्रिया योजनाएं, टेबलटॉप अभ्यास, उल्लंघन अधिसूचना</td></tr>
        <tr><td><code>SCM</code></td><td>Supply Chain &amp; Third Party</td><td>10</td><td>विक्रेता प्रबंधन, आपूर्ति श्रृंखला जोखिम, अनुबंध</td></tr>
        <tr><td><code>PHY</code></td><td>Physical &amp; Environmental</td><td>8</td><td>डेटा केंद्र, बैज पहुंच, CCTV, पर्यावरणीय नियंत्रण</td></tr>
        <tr><td><code>HRS</code></td><td>Human Resources Security</td><td>10</td><td>पृष्ठभूमि जांच, सुरक्षा प्रशिक्षण, समाप्ति प्रक्रियाएं</td></tr>
        <tr><td><code>BCP</code></td><td>Business Continuity</td><td>10</td><td>बैकअप, आपदा पुनर्प्राप्ति, BCP परीक्षण, RTO/RPO</td></tr>
        <tr><td><code>CRY</code></td><td>Cryptography &amp; Key Management</td><td>8</td><td>एन्क्रिप्शन मानक, कुंजी रोटेशन, प्रमाणपत्र प्रबंधन</td></tr>
        <tr><td><code>CMP</code></td><td>Compliance &amp; Assurance</td><td>9</td><td>नियामक अनुपालन, आंतरिक ऑडिट, बाहरी ऑडिट तत्परता</td></tr>
    </table>

    <h3>प्रश्नों के उत्तर कैसे दें</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Assessment Questionnaire</span> पर जाएं।</li>
        <li>यदि आपके पास एकाधिक मूल्यांकन हैं, तो पृष्ठ के शीर्ष पर ड्रॉपडाउन से सही एक चुनें।</li>
        <li>आपको 14 सुरक्षा डोमेन सूचीबद्ध दिखाई देंगे। किसी डोमेन नाम पर क्लिक करें (जैसे, <strong>GOV - Governance &amp; Leadership</strong>) उसे विस्तारित करने और उसके प्रश्न देखने के लिए।</li>
        <li>प्रत्येक प्रश्न के लिए, आपको दो जानकारी प्रदान करनी होगी:
            <ul>
                <li><span class="field-label">Maturity Rating</span> (1-4) &mdash; इस नियंत्रण का आपके संगठन का कार्यान्वयन कितना परिपक्व है?
                    <ul>
                        <li><strong>1 &mdash; Initial/Ad Hoc:</strong> कोई औपचारिक प्रक्रिया नहीं। असंगत रूप से या बिल्कुल नहीं किया गया।</li>
                        <li><strong>2 &mdash; Developing:</strong> कुछ प्रक्रियाएं मौजूद हैं लेकिन लगातार पालन नहीं किया जाता। आंशिक रूप से दस्तावेज़ीकृत।</li>
                        <li><strong>3 &mdash; Defined:</strong> औपचारिक, दस्तावेज़ीकृत प्रक्रियाएं मौजूद हैं और लगातार पालन की जाती हैं।</li>
                        <li><strong>4 &mdash; Managed/Optimized:</strong> प्रक्रियाएं मापी जाती हैं, निगरानी की जाती हैं और लगातार सुधारी जाती हैं।</li>
                    </ul>
                </li>
                <li><span class="field-label">Conformity Status</span> &mdash; इस प्रश्न के लिए आपकी अनुपालन स्थिति:
                    <ul>
                        <li><strong>Conforming</strong> &mdash; पूरी तरह से लागू और आवश्यकता को पूरा करता है</li>
                        <li><strong>Partial</strong> &mdash; आंशिक रूप से लागू; कुछ अंतराल बने हुए हैं</li>
                        <li><strong>Non-Conforming</strong> &mdash; लागू नहीं किया गया या आवश्यकता को पूरा नहीं करता</li>
                        <li><strong>Not Applicable</strong> &mdash; यह प्रश्न आपके संगठन पर लागू नहीं होता</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li>वैकल्पिक रूप से, अपने उत्तर की व्याख्या करने के लिए <span class="field-label">Notes</span> जोड़ें। यह अत्यधिक अनुशंसित है &mdash; लेखापरीक्षक आपका तर्क देखना चाहेंगे।</li>
        <li>आपकी प्रतिक्रियाएं काम करते समय <strong>स्वतः-सहेजी</strong> जाती हैं। आपको सहेजें बटन क्लिक करने की आवश्यकता नहीं है।</li>
        <li>सभी 14 डोमेन में प्रश्नों के उत्तर देते रहें। आपको एक सत्र में सब कुछ पूरा करने की आवश्यकता नहीं है &mdash; जारी रखने के लिए कभी भी वापस आएं।</li>
    </ol>

    <div class="callout callout-info">
        <strong>सुझाव &mdash; परिपक्वता अनुरूपता को प्रेरित करती है:</strong> जब आप परिपक्वता रेटिंग सेट करते हैं, तो सिस्टम स्वचालित रूप से अनुरूपता स्थिति प्राप्त कर सकता है: Maturity 3-4 = Conforming, Maturity 2 = Partial, Maturity 1 = Non-Conforming। आप इसे आवश्यकतानुसार ओवरराइड कर सकते हैं।
    </div>

    <div class="callout callout-warning">
        <strong>महत्वपूर्ण:</strong> आप जिस प्रश्न का उत्तर देते हैं वह एकाधिक फ्रेमवर्क में आवश्यकताओं से मैप होता है। उदाहरण के लिए, "Multi-Factor Authentication" (IAM डोमेन में) के बारे में एक प्रश्न का उत्तर देने से एक साथ SOC 2, ISO 27001, PCI DSS, NIST CSF और CMMC के लिए आपके अनुपालन स्कोर अपडेट होते हैं। आपको कभी भी एक ही अवधारणा का दो बार उत्तर देने की आवश्यकता नहीं है।
    </div>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment-questions.png" alt="Answering questions in the assessment questionnaire" loading="lazy">
        <figcaption><strong>प्रश्नावली का उत्तर देना।</strong> हेडर काम करते समय <em>Progress</em>, लाइव <em>CSF Maturity</em>, और <em>Compliance</em> ट्रैक करता है। डोमेन टैब (GOV, IAM, DSP, &hellip;) प्रत्येक उस डोमेन का वर्तमान स्कोर दिखाता है; उसके प्रश्नों पर जाने के लिए एक पर क्लिक करें। प्रत्येक प्रश्न के लिए आप एक <strong>Maturity</strong> रेटिंग (1&ndash;4 या N/A) और एक <strong>Conformity</strong> स्थिति सेट करते हैं &mdash; उत्तर स्वतः-सहेजे जाते हैं। जो बचा है उसे खोजने के लिए <span class="btn-label">Show Unanswered Questions</span> उपयोग करें।</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step3">
    <h2>चरण 3: साक्ष्य अपलोड करें</h2>
    <p>साक्ष्य साबित करता है कि आपके उत्तर सटीक हैं। लेखापरीक्षक प्रत्येक अनुपालन दावे के लिए साक्ष्य देखने की उम्मीद करेंगे। साक्ष्य में स्क्रीनशॉट, कॉन्फ़िगरेशन निर्यात, नीति दस्तावेज़, ऑडिट लॉग, प्रमाणपत्र और अधिक शामिल हो सकते हैं।</p>

    <h3>मूल्यांकन के दौरान साक्ष्य कैसे अपलोड करें</h3>
    <ol class="steps">
        <li><span class="menu-label">Assessment Questionnaire</span> में एक प्रश्न का उत्तर देते समय, प्रश्न प्रतिक्रिया क्षेत्र के नीचे <strong>Evidence</strong> अनुभाग देखें।</li>
        <li><span class="btn-label">Upload Evidence</span> या अटैचमेंट आइकन पर क्लिक करें।</li>
        <li>अपने कंप्यूटर से एक फ़ाइल चुनें। समर्थित प्रकारों में PDF, छवियां (PNG, JPG), Word दस्तावेज़, Excel स्प्रेडशीट और टेक्स्ट फ़ाइलें शामिल हैं।</li>
        <li>साक्ष्य को एक वर्णनात्मक <span class="field-label">Title</span> दें (जैसे, "MFA Configuration Screenshot - Okta Admin Console")।</li>
        <li>साक्ष्य स्वचालित रूप से वर्तमान मूल्यांकन प्रश्न से लिंक होता है।</li>
        <li>आप प्रति प्रश्न एकाधिक साक्ष्य फ़ाइलें अपलोड कर सकते हैं।</li>
    </ol>

    <div class="callout callout-success">
        <strong>सुरक्षा:</strong> सभी अपलोड की गई साक्ष्य फ़ाइलें डेटाबेस में संग्रहीत होने से पहले एन्क्रिप्ट (AES-256-CBC) की जाती हैं। जब आप साक्ष्य डाउनलोड करते हैं, तो इसे तुरंत डिक्रिप्ट किया जाता है। यह सुनिश्चित करता है कि संवेदनशील अनुपालन दस्तावेज़ विश्राम में सुरक्षित हैं।
    </div>

    <h3>साक्ष्य लाइब्रेरी</h3>
    <p>आप <span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Evidence Library</span> के माध्यम से भी साक्ष्य अलग से प्रबंधित कर सकते हैं। यह पृष्ठ प्रकार, स्थिति और समाप्ति तिथि के अनुसार फ़िल्टरिंग के साथ सभी मूल्यांकन और नियंत्रणों में सभी साक्ष्य दिखाता है।</p>
</div>


<div class="doc-section" id="grc-step4">
    <h2>चरण 4: अपने अनुपालन स्कोर देखें</h2>
    <p>जैसे ही आप प्रश्नों के उत्तर देते हैं, प्लेटफ़ॉर्म वास्तविक समय में प्रत्येक फ्रेमवर्क के लिए आपके अनुपालन प्रतिशत की गणना करता है।</p>

    <h3>Frameworks पृष्ठ पर स्कोर देखना</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span> पर जाएं।</li>
        <li>पृष्ठ के शीर्ष पर, आपको एक <span class="field-label">Assessment</span> ड्रॉपडाउन दिखाई देगा। उस मूल्यांकन का चयन करें जिसके लिए आप स्कोर देखना चाहते हैं। डिफ़ॉल्ट रूप से, सबसे हालिया मूल्यांकन चुना जाता है।</li>
        <li>ड्रॉपडाउन के नीचे, आपको फ्रेमवर्क कार्ड दिखाई देंगे &mdash; प्रत्येक अनुपालन फ्रेमवर्क के लिए एक जिसमें प्रश्न मैप किए गए हैं। प्रत्येक कार्ड दिखाता है:
            <ul>
                <li>एक <strong>donut chart</strong> जो समग्र अनुपालन प्रतिशत दिखाता है (जैसे, 75%)</li>
                <li><strong>फ्रेमवर्क कोड और नाम</strong> (जैसे, "SOC2 &mdash; SOC 2 Type II")</li>
                <li><strong>औसत परिपक्वता</strong> स्कोर (यदि परिपक्वता डेटा मौजूद है, जैसे "3.50 / 4.00" के रूप में प्रदर्शित)</li>
                <li>मीट्रिक गणना: <strong>Conforming</strong>, <strong>Partial</strong>, <strong>Non-Conforming</strong>, और <strong>Total Mapped</strong></li>
            </ul>
        </li>
        <li>उस फ्रेमवर्क के लिए विस्तृत <strong>Compliance Report</strong> खोलने के लिए किसी भी फ्रेमवर्क कार्ड पर क्लिक करें।</li>
    </ol>

    <h3>अनुपालन प्रतिशत गणना</h3>
    <p>अनुपालन प्रतिशत इस प्रकार गणना की जाती है:</p>
    <div class="example-box">
        <strong>सूत्र:</strong> <code>(Conforming + Partial &times; 0.5) &divide; Applicable Requirements &times; 100</code><br><br>
        &bull; <strong>Conforming</strong> आवश्यकताएं 100% पूर्ण के रूप में गिनी जाती हैं<br>
        &bull; <strong>Partial</strong> आवश्यकताएं 50% पूर्ण के रूप में गिनी जाती हैं<br>
        &bull; <strong>Not Applicable</strong> आवश्यकताएं गणना से बाहर की जाती हैं<br>
        &bull; <strong>Non-Conforming</strong> और <strong>Not Assessed</strong> आवश्यकताएं 0% के रूप में गिनी जाती हैं
    </div>

    <h3>वर्तमान में समर्थित फ्रेमवर्क</h3>
    <table class="doc-table">
        <tr><th>फ्रेमवर्क</th><th>संस्करण</th><th>मैप किए गए प्रश्न</th></tr>
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
    <h2>चरण 5: फ्रेमवर्क अनुपालन रिपोर्ट बनाएं</h2>
    <p>एक बार जब आप प्रश्नों के उत्तर दे देते हैं, तो आप किसी भी फ्रेमवर्क के लिए एक विस्तृत अनुपालन रिपोर्ट बना सकते हैं। यह रिपोर्ट लेखापरीक्षकों, नियामकों या प्रबंधन के साथ साझा करने के लिए उपयुक्त है।</p>

    <h3>रिपोर्ट कैसे बनाएं</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span> पर जाएं।</li>
        <li>शीर्ष पर <span class="field-label">Assessment</span> ड्रॉपडाउन से अपना मूल्यांकन चुनें।</li>
        <li>उस फ्रेमवर्क कार्ड पर क्लिक करें जिस पर आप रिपोर्ट करना चाहते हैं (जैसे, "SOC2 &mdash; SOC 2 Type II")।</li>
        <li><strong>Framework Compliance Report</strong> पृष्ठ खुलता है, जो दिखाता है:
            <ul>
                <li><strong>रिपोर्ट हेडर</strong> &mdash; फ्रेमवर्क नाम, मूल्यांकन शीर्षक, प्रकार, स्थिति, दायरा, प्रमुख लेखापरीक्षक, तिथियां और समग्र अनुपालन प्रतिशत</li>
                <li><strong>सारांश आंकड़े</strong> &mdash; क्लिक करने योग्य कार्ड जो कुल आवश्यकताएं, Conforming, Partial, Non-Conforming, Not Assessed और N/A गणनाएं दिखाते हैं</li>
                <li><strong>आवश्यकता कार्ड</strong> &mdash; प्रति फ्रेमवर्क आवश्यकता एक कार्ड, जो आवश्यकता संदर्भ, शीर्षक, स्थिति बैज और उनकी प्रतिक्रियाओं के साथ सभी मैप किए गए प्रश्न दिखाता है</li>
            </ul>
        </li>
        <li>स्थिति के अनुसार <strong>आवश्यकताओं को फ़िल्टर करने</strong> के लिए, शीर्ष पर किसी भी सारांश आंकड़े कार्ड पर क्लिक करें। उदाहरण के लिए, केवल non-conforming आवश्यकताएं दिखाने के लिए <strong>Non-Conforming</strong> पर क्लिक करें। सभी दिखाने के लिए फिर से क्लिक करें (या "Total Requirements" पर क्लिक करें)।</li>
        <li><strong>रिपोर्ट प्रिंट करने के लिए</strong>, शीर्ष पर <span class="btn-label">Print Report</span> बटन पर क्लिक करें। आपके ब्राउज़र का प्रिंट डायलॉग खुलेगा। आप कागज पर प्रिंट कर सकते हैं या PDF फ़ाइल बनाने के लिए "Save as PDF" चुन सकते हैं।</li>
    </ol>

    <h3>प्रत्येक आवश्यकता कार्ड क्या दिखाता है</h3>
    <p>रिपोर्ट में प्रत्येक आवश्यकता के लिए, आपको दिखाई देगा:</p>
    <ul>
        <li><strong>आवश्यकता संदर्भ</strong> &mdash; आधिकारिक संदर्भ संख्या (जैसे, SOC 2 के लिए "CC6.1")</li>
        <li><strong>आवश्यकता शीर्षक</strong> &mdash; आवश्यकता क्या कहती है</li>
        <li><strong>स्थिति बैज</strong> &mdash; रंग-कोडित: हरा (Conforming), एम्बर (Partial), लाल (Non-Conforming), ग्रे (Not Assessed / N/A)</li>
        <li><strong>मैप किए गए प्रश्न</strong> &mdash; प्रत्येक प्रश्न जो इस आवश्यकता से मैप होता है, दिखाता है:
            <ul>
                <li>प्रश्न संदर्भ और पाठ</li>
                <li>परिपक्वता रेटिंग (1-4) एक दृश्य बार के साथ</li>
                <li>अनुरूपता स्थिति</li>
                <li>सत्यापन स्थिति (Pending, Validated, Rejected, Needs Review)</li>
                <li>मूल्यांकनकर्ता नाम और तिथि</li>
                <li>मैपिंग शक्ति (Exact, Strong, Partial, Related)</li>
                <li>मूल्यांकनकर्ता नोट</li>
                <li>सत्यापन नोट</li>
                <li>साक्ष्य अटैचमेंट (डाउनलोड लिंक के साथ)</li>
            </ul>
        </li>
    </ul>
</div>


<div class="doc-section" id="grc-fairscore">
    <h2>CSF Maturity Score डैशबोर्ड</h2>
    <p><strong>CSF Maturity Score</strong> पृष्ठ एक दृश्य डैशबोर्ड प्रदान करता है जो NIST Cybersecurity Framework के अनुरूप, सभी 14 सुरक्षा डोमेन में आपके संगठन की परिपक्वता दिखाता है।</p>

    <h3>कैसे पहुंचें</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">CSF Maturity Score</span> पर जाएं।</li>
        <li>यदि आपके पास एकाधिक मूल्यांकन हैं, तो ड्रॉपडाउन से वांछित एक चुनें।</li>
        <li>पृष्ठ दिखाता है:
            <ul>
                <li><strong>Overall FAIR Score</strong> &mdash; सभी डोमेन में एक भारित औसत परिपक्वता स्कोर</li>
                <li><strong>Radar Chart</strong> &mdash; सभी 14 डोमेन में आपके स्कोर प्लॉट करने वाला एक दृश्य स्पाइडर/रडार चार्ट</li>
                <li><strong>Domain Score Cards</strong> &mdash; प्रत्येक डोमेन के लिए व्यक्तिगत कार्ड जो औसत परिपक्वता, उत्तर दिए गए प्रश्न और अनुरूपता विवरण दिखाते हैं</li>
                <li><strong>Framework Compliance Bars</strong> &mdash; प्रति फ्रेमवर्क अनुपालन प्रतिशत दिखाने वाली क्षैतिज पट्टियां</li>
                <li><strong>Gap Analysis Summary</strong> &mdash; वे डोमेन जहां स्कोर लक्ष्य से नीचे हैं</li>
            </ul>
        </li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grc-fairscore.png" alt="CSF Maturity Score dashboard with radar chart" loading="lazy">
        <figcaption><strong>GRC Module &rarr; CSF Maturity Score.</strong> चार हेडलाइन टाइलें &mdash; <em>CSF Maturity Score</em> (1&ndash;4 पैमाना), <em>Compliance Rate</em>, <em>Questions Answered</em>, और <em>Gaps Found</em> &mdash; एक नज़र में आपकी स्थिति का सारांश देती हैं। <strong>Security Domain Maturity Radar</strong> सभी 14 डोमेन प्लॉट करता है, और दाईं ओर की सूची प्रत्येक डोमेन का सटीक औसत स्कोर देती है। शीर्ष पर ड्रॉपडाउन से वह मूल्यांकन चुनें जो आप चाहते हैं।</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-gaps">
    <h2>Gap Analysis</h2>
    <p><strong>Gap Analysis</strong> पृष्ठ एक मूल्यांकन के दौरान पाई गई हर कमज़ोरी &mdash; हर प्रश्न जिसका उत्तर <strong>Non-Conforming</strong> या <strong>Partial</strong> था &mdash; को एक प्राथमिकतापूर्ण कार्यसूची में एकत्र करता है। यह प्रश्न का उत्तर देता है "हम कहाँ कम पड़ रहे हैं, और प्रत्येक कमी किसे प्रभावित करती है?"</p>

    <h3>कैसे पहुंचें</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Gaps</span> पर जाएं।</li>
        <li><span class="field-label">Assessment</span> ड्रॉपडाउन से वह मूल्यांकन चुनें जिसका आप विश्लेषण करना चाहते हैं।</li>
    </ol>

    <h3>पृष्ठ क्या दिखाता है</h3>
    <p>शीर्ष पर चार सारांश टाइलें आपके <strong>Total Gaps</strong>, <strong>Non-Conforming</strong>, <strong>Partial</strong>, और <strong>With Linked Risk</strong> गैप गिनती हैं। उनके नीचे, प्रत्येक गैप एक पंक्ति के रूप में सूचीबद्ध है जिसमें:</p>
    <ul>
        <li><strong>गंभीरता</strong> &mdash; एक बैज: <em>Non-Conforming</em> (लाल) या <em>Partial</em> (एम्बर)।</li>
        <li><strong>डोमेन</strong> और <strong>Ref</strong> &mdash; सुरक्षा डोमेन और सटीक प्रश्न संदर्भ (जैसे, <code>GOV-08</code>)।</li>
        <li><strong>Finding</strong> &mdash; प्रश्न पाठ जो वर्णन करता है कि क्या गायब है।</li>
        <li><strong>Framework Impact</strong> &mdash; हर उस फ्रेमवर्क आवश्यकता के लिए बैज जिसे यह गैप प्रभावित करता है, ताकि आप एक नज़र में देख सकें कि एक एकल सुधार एक साथ SOC 2, ISO 27001, PCI DSS और अधिक में सुधार करता है।</li>
        <li><strong>Risk</strong> &mdash; क्या इस गैप के लिए कोई जोखिम लॉग किया गया है, और पूर्ण विवरण खोलने के लिए एक <span class="btn-label">View</span> क्रिया।</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/grc-gaps.png" alt="Gap Analysis page listing non-conforming and partial findings" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Gaps.</strong> प्रत्येक non-conforming या partial प्रतिक्रिया एक गैप बन जाती है। <strong>Framework Impact</strong> कॉलम दिखाता है कि प्रत्येक फ्रेमवर्क में कौन सी आवश्यकताएं गैप को छूती हैं &mdash; एक गैप बंद करने से कई फ्रेमवर्क एक साथ उठ सकते हैं।</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-frameworks">
    <h2>Frameworks पृष्ठ</h2>
    <p><strong>Frameworks</strong> पृष्ठ सभी समर्थित फ्रेमवर्क में अनुपालन स्थिति देखने के लिए आपका केंद्रीय हब है। यह मूल्यांकन-संचालित अनुपालन डेटा दिखाता है।</p>

    <h3>कैसे उपयोग करें</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Frameworks</span> पर जाएं।</li>
        <li><span class="field-label">Assessment</span> ड्रॉपडाउन से एक मूल्यांकन चुनें। पृष्ठ आपके सबसे हालिया मूल्यांकन पर डिफ़ॉल्ट होता है।</li>
        <li>पृष्ठ एक ग्रिड में फ्रेमवर्क कार्ड प्रदर्शित करता है। केवल मैप किए गए प्रश्नों वाले फ्रेमवर्क दिखाई देते हैं। प्रत्येक कार्ड अनुपालन प्रतिशत, परिपक्वता स्कोर और मीट्रिक गणनाएं दिखाता है।</li>
        <li>विस्तृत अनुपालन रिपोर्ट खोलने के लिए एक फ्रेमवर्क कार्ड पर क्लिक करें।</li>
    </ol>

    <figure class="doc-figure">
        <img src="app/docs/grc-frameworks.png" alt="Compliance Frameworks page with per-framework compliance cards" loading="lazy">
        <figcaption><strong>GRC Module &rarr; Frameworks.</strong> शीर्ष टाइलें आपके फ्रेमवर्क, औसत तत्परता, कुल आवश्यकताएं और कितनी <em>ध्यान की जरूरत</em> है, गिनती हैं। प्रत्येक कार्ड एक फ्रेमवर्क का अनुपालन donut, उसकी औसत परिपक्वता और Conforming / Partial / Non-Conforming / Total-Mapped विवरण दिखाता है। उस फ्रेमवर्क की पूर्ण अनुपालन रिपोर्ट खोलने के लिए किसी भी कार्ड पर क्लिक करें।</figcaption>
    </figure>

    <h3>Framework Requirement Tree</h3>
    <p>यदि आप इस पृष्ठ पर मूल्यांकन <em>चुने बिना</em> जाते हैं (या कहीं और से एक फ्रेमवर्क लिंक पर क्लिक करके), तो आपको <strong>Requirement Tree</strong> दृश्य दिखाई देगा। यह एक फ्रेमवर्क के भीतर सभी आवश्यकताओं की पदानुक्रमिक संरचना, साथ ही मैप किए गए नियंत्रण और कार्यान्वयन स्थिति दिखाता है। प्रशासक और Cyber GRC उपयोगकर्ता यहां कस्टम आवश्यकताएं जोड़, संपादित और हटा सकते हैं।</p>
</div>


<div class="doc-section" id="grc-controls">
    <h2>आंतरिक नियंत्रण</h2>
    <p><strong>Internal Controls</strong> वे विशिष्ट सुरक्षा उपाय हैं जो आपके संगठन ने लागू किए हैं। उदाहरण: "सभी सिस्टम पर Multi-Factor Authentication," "दैनिक एन्क्रिप्टेड बैकअप," "वार्षिक पेनेट्रेशन परीक्षण।"</p>

    <h3>नियंत्रण कैसे बनाएं</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Internal Controls</span> पर जाएं।</li>
        <li><span class="btn-label">+ New Control</span> पर क्लिक करें।</li>
        <li>फ़ील्ड भरें:
            <ul>
                <li><span class="field-label">Control Title</span> &mdash; एक छोटा नाम (जैसे, "MFA for all user accounts")</li>
                <li><span class="field-label">Description</span> &mdash; यह नियंत्रण क्या करता है इसका विस्तृत विवरण</li>
                <li><span class="field-label">Control Type</span> &mdash; Preventive, Detective, Corrective, या Directive</li>
                <li><span class="field-label">Category</span> &mdash; Technical, Administrative, या Physical</li>
                <li><span class="field-label">Implementation Status</span> &mdash; Planned, In Progress, Implemented, या Not Applicable</li>
                <li><span class="field-label">Effectiveness</span> &mdash; Not Tested, Ineffective, Partially Effective, या Effective</li>
                <li><span class="field-label">Risk Level</span> &mdash; Low, Medium, High, या Critical</li>
                <li><span class="field-label">Owner</span> &mdash; जिम्मेदार व्यक्ति (Administrator और Cyber GRC समूह के सदस्यों तक सीमित)</li>
                <li><span class="field-label">Test Frequency</span> &mdash; यह नियंत्रण कितनी बार परीक्षण किया जाता है (Daily, Weekly, Monthly, आदि)</li>
            </ul>
        </li>
        <li><strong>Framework Mapping</strong> के अंतर्गत, चुनें कि यह नियंत्रण कौन सी फ्रेमवर्क आवश्यकताएं पूरी करता है। आप एक नियंत्रण को एकाधिक फ्रेमवर्क की आवश्यकताओं से मैप कर सकते हैं।</li>
        <li><span class="btn-label">Save</span> पर क्लिक करें।</li>
    </ol>

    <div class="callout callout-success">
        <strong>मुख्य लाभ &mdash; क्रॉस-फ्रेमवर्क मैपिंग:</strong> "MFA" जैसा एक एकल नियंत्रण एक साथ SOC 2 (CC6.1), ISO 27001 (A.8.5), PCI DSS (8.4.2), और NIST CSF (PR.AC-7) में आवश्यकताओं को पूरा कर सकता है। इसे एक बार मैप करें और यह सभी फ्रेमवर्क को कवर करता है।
    </div>
</div>


<div class="doc-section" id="grc-crosswalk">
    <h2>Framework Crosswalk</h2>
    <p><strong>Framework Crosswalk</strong> दिखाता है कि एक फ्रेमवर्क का अनुपालन स्वचालित रूप से दूसरे के लिए कवरेज कैसे प्रदान करता है। उदाहरण के लिए, यदि आप SOC 2 अनुरूप हैं, तो आप ISO 27001 का कितना हिस्सा पहले से कवर कर चुके हैं?</p>

    <h3>कैसे उपयोग करें</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">Framework Crosswalk</span> पर जाएं।</li>
        <li>एक <span class="field-label">Source Framework</span> चुनें (वह फ्रेमवर्क जो आपने पहले से पूरी की है, जैसे, "SOC 2")।</li>
        <li>एक <span class="field-label">Target Framework</span> चुनें (वह फ्रेमवर्क जिसके विरुद्ध आप तुलना करना चाहते हैं, जैसे, "ISO 27001")।</li>
        <li>crosswalk तालिका दिखाती है कि आपके स्रोत नियंत्रणों द्वारा कौन सी लक्ष्य आवश्यकताएं कवर की गई हैं, और किसमें गैप हैं।</li>
    </ol>
</div>


<div class="doc-section" id="grc-evidence">
    <h2>Evidence Library</h2>
    <p><strong>Evidence Library</strong> आपके संगठन में सभी अनुपालन साक्ष्य के लिए एक केंद्रीकृत भंडार है।</p>

    <h3>साक्ष्य कैसे अपलोड करें</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Evidence Library</span> पर जाएं।</li>
        <li><span class="btn-label">+ Upload Evidence</span> पर क्लिक करें।</li>
        <li>भरें: <span class="field-label">Title</span>, <span class="field-label">Evidence Type</span> (screenshot, document, certificate, configuration, report, आदि), <span class="field-label">Description</span>, और वैकल्पिक रूप से एक <span class="field-label">Expiry Date</span>।</li>
        <li>अपलोड करने के लिए फ़ाइल चुनें।</li>
        <li><span class="btn-label">Upload</span> पर क्लिक करें। फ़ाइल एन्क्रिप्ट और सुरक्षित रूप से संग्रहीत की जाती है।</li>
        <li>आप फिर इस साक्ष्य को विशिष्ट नियंत्रणों या मूल्यांकन प्रतिक्रियाओं से लिंक कर सकते हैं।</li>
    </ol>

    <h3>साक्ष्य स्थितियाँ</h3>
    <table class="doc-table">
        <tr><th>स्थिति</th><th>अर्थ</th></tr>
        <tr><td><strong>Current</strong></td><td>सक्रिय, वैध साक्ष्य</td></tr>
        <tr><td><strong>Expired</strong></td><td>समाप्ति तिथि बीत गई &mdash; ताज़ा करने की आवश्यकता है</td></tr>
        <tr><td><strong>Superseded</strong></td><td>नए साक्ष्य द्वारा प्रतिस्थापित</td></tr>
        <tr><td><strong>Draft</strong></td><td>अपलोड किया गया लेकिन अभी तक समीक्षा या अंतिम रूप नहीं दिया गया</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-policies">
    <h2>Policy Management</h2>
    <p><strong>Policies</strong> पृष्ठ एक पूर्ण नीति जीवनचक्र प्रदान करता है &mdash; मसौदे से अनुमोदन, प्रकाशन और आवधिक समीक्षा तक।</p>

    <h3>नीति कैसे बनाएं</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Policy Management</span> &rarr; <span class="menu-label">Policies</span> पर जाएं।</li>
        <li><span class="btn-label">+ New Policy</span> पर क्लिक करें।</li>
        <li>भरें: <span class="field-label">Title</span>, <span class="field-label">Category</span> (Security, Privacy, Compliance, Operational, HR, IT, आदि), <span class="field-label">Review Frequency</span> (नीति की कितनी बार समीक्षा होनी चाहिए)।</li>
        <li>रिच टेक्स्ट एडिटर का उपयोग करके नीति सामग्री लिखें।</li>
        <li><span class="btn-label">Save</span> पर क्लिक करें। नीति <span class="status-label">Draft</span> स्थिति में बनाई गई है।</li>
        <li>जब तैयार हो, <strong>Review</strong> &rarr; <strong>Approve</strong> &rarr; <strong>Publish</strong> के लिए सबमिट करें।</li>
    </ol>

    <h3>नीति जीवनचक्र</h3>
    <p><span class="status-label">Draft</span> &rarr; <span class="status-label">Review</span> &rarr; <span class="status-label">Approved</span> &rarr; <span class="status-label">Published</span> &rarr; (आवधिक समीक्षा या <span class="status-label">Retired</span>)</p>
</div>


<div class="doc-section" id="grc-audits">
    <h2>Audits &amp; Findings</h2>
    <p><strong>Audits</strong> पृष्ठ पूर्ण ऑडिट जीवनचक्र का प्रबंधन करता है &mdash; योजना से फ़ील्डवर्क, निष्कर्ष, उपचार और बंद करने तक।</p>

    <h3>ऑडिट कैसे बनाएं</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Audits</span> पर जाएं।</li>
        <li><span class="btn-label">+ New Audit</span> पर क्लिक करें।</li>
        <li>भरें: <span class="field-label">Title</span>, <span class="field-label">Audit Type</span> (Internal, External, Certification, Surveillance, Readiness), <span class="field-label">Framework</span>, <span class="field-label">Lead Auditor</span>, <span class="field-label">Planned Start/End Dates</span>।</li>
        <li><span class="btn-label">Create</span> पर क्लिक करें।</li>
    </ol>

    <h3>निष्कर्ष रिकॉर्ड करना</h3>
    <ol class="steps">
        <li>एक ऑडिट खोलें और <span class="btn-label">+ Add Finding</span> पर क्लिक करें।</li>
        <li>भरें: <span class="field-label">Title</span>, <span class="field-label">Severity</span> (Informational, Low, Medium, High, Critical), <span class="field-label">Finding Type</span> (Nonconformity, Observation, Opportunity, Strength), और <span class="field-label">Description</span>।</li>
        <li>निष्कर्ष को विशिष्ट फ्रेमवर्क आवश्यकताओं या नियंत्रणों से मैप करें।</li>
        <li>एक नियत तिथि के साथ टीम के सदस्य को उपचार असाइन करें।</li>
        <li><strong>Verified Closed</strong> स्थिति तक उपचार प्रगति ट्रैक करें।</li>
    </ol>

    <h3>ऑडिट स्थितियाँ</h3>
    <table class="doc-table">
        <tr><th>स्थिति</th><th>अर्थ</th></tr>
        <tr><td><strong>Planning</strong></td><td>दायरा, उद्देश्य और शेड्यूल परिभाषित करना</td></tr>
        <tr><td><strong>Fieldwork</strong></td><td>सक्रिय परीक्षण, साक्ष्य समीक्षा और साक्षात्कार</td></tr>
        <tr><td><strong>Reporting</strong></td><td>ऑडिट रिपोर्ट का मसौदा तैयार करना और निष्कर्ष दस्तावेज़ीकृत करना</td></tr>
        <tr><td><strong>Remediation</strong></td><td>निष्कर्ष रिपोर्ट किए गए हैं; टीम मुद्दों को ठीक कर रही है</td></tr>
        <tr><td><strong>Closed</strong></td><td>सभी निष्कर्ष हल हुए और ऑडिट पूर्ण</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-risks">
    <h2>Risk Register</h2>
    <p><strong>Risk Register</strong> संभावना/प्रभाव स्कोरिंग, उपचार योजनाओं और नियंत्रणों के लिंक के साथ संगठनात्मक जोखिम ट्रैक करता है।</p>

    <h3>जोखिम कैसे जोड़ें</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Risk Register</span> पर जाएं।</li>
        <li><span class="btn-label">+ New Risk</span> पर क्लिक करें।</li>
        <li>भरें: <span class="field-label">Title</span>, <span class="field-label">Description</span>, <span class="field-label">Category</span> (Strategic, Operational, Financial, Compliance, Reputational, Technology, Third Party)।</li>
        <li><span class="field-label">Likelihood</span> (Rare, Unlikely, Possible, Likely, Almost Certain) और <span class="field-label">Impact</span> (Insignificant, Minor, Moderate, Major, Catastrophic) सेट करें।</li>
        <li>सिस्टम <strong>Inherent Risk Score</strong> (Likelihood &times; Impact, 1-25 पैमाने पर) की गणना करता है।</li>
        <li>एक <span class="field-label">Treatment Strategy</span> चुनें: Accept, Mitigate, Transfer, या Avoid।</li>
        <li>यह दिखाने के लिए प्रासंगिक आंतरिक नियंत्रण लिंक करें कि जोखिम को कैसे कम किया जा रहा है। सिस्टम नियंत्रणों के बाद <strong>Residual Risk Score</strong> की गणना करता है।</li>
    </ol>
</div>


<div class="doc-section" id="grc-monitors">
    <h2>Continuous Monitors</h2>
    <p><strong>Continuous Monitors</strong> स्वचालित जांच हैं जो एक शेड्यूल पर (प्रति घंटे, दैनिक, साप्ताहिक या मासिक) आपके सुरक्षा नियंत्रण सत्यापित करती हैं।</p>

    <h3>Monitor कैसे बनाएं</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Evidence &amp; Monitoring</span> &rarr; <span class="menu-label">Continuous Monitors</span> पर जाएं।</li>
        <li><span class="btn-label">+ New Monitor</span> पर क्लिक करें।</li>
        <li>भरें: <span class="field-label">Title</span>, <span class="field-label">Check Type</span>, <span class="field-label">Frequency</span> (Hourly, Daily, Weekly, Monthly), और <span class="field-label">Collector Configuration</span> (जांच के लिए JSON सेटिंग)।</li>
        <li>Monitor को एक आंतरिक नियंत्रण से लिंक करें।</li>
        <li>Monitor सक्षम करें। यह कॉन्फ़िगर किए गए शेड्यूल पर स्वचालित रूप से चलेगा।</li>
        <li>Monitor विवरण पृष्ठ पर परिणाम (Pass, Fail, Error, Warning) और निष्पादन इतिहास देखें।</li>
    </ol>
</div>


<div class="doc-section" id="grc-tasks">
    <h2>Task Inbox</h2>
    <p><strong>Task Inbox</strong> सभी मूल्यांकन में आपको असाइन किए गए सभी GRC कार्य दिखाता है। मूल्यांकन के दौरान साक्ष्य संग्रह, उपचार, समीक्षा या दस्तावेज़ीकरण जैसे काम सौंपने के लिए कार्य बनाए जाते हैं।</p>

    <h3>कैसे उपयोग करें</h3>
    <ol class="steps">
        <li><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Assessment &amp; Audit</span> &rarr; <span class="menu-label">Task Inbox</span> पर जाएं।</li>
        <li>आपको आपको असाइन किए गए कार्यों की एक सूची दिखाई देगी। प्रत्येक कार्य दिखाता है: शीर्षक, प्रकार (Evidence Request, Remediation, Review, Documentation, Implementation), प्राथमिकता, नियत तिथि और स्थिति।</li>
        <li>विवरण देखने और उसकी स्थिति अपडेट करने के लिए एक कार्य पर क्लिक करें।</li>
        <li>शुरू करने पर कार्यों को <span class="status-label">In Progress</span> और पूरा होने पर <span class="status-label">Completed</span> के रूप में चिह्नित करें।</li>
    </ol>
</div>


<div class="doc-section" id="grc-dashboard">
    <h2>GRC Dashboard</h2>
    <p><strong>GRC Dashboard</strong> आपका अनुपालन कमांड सेंटर है &mdash; आपकी पूरी GRC स्थिति का एक एकल-पृष्ठ अवलोकन।</p>

    <h3>डैशबोर्ड क्या दिखाता है</h3>
    <ul>
        <li><strong>Framework Compliance Heatmap</strong> &mdash; प्रत्येक फ्रेमवर्क के लिए रंग-कोडित अनुपालन प्रतिशत</li>
        <li><strong>Control Implementation Progress</strong> &mdash; कितने नियंत्रण लागू बनाम नियोजित हैं</li>
        <li><strong>Evidence Freshness</strong> &mdash; कितने साक्ष्य आइटम वर्तमान, समाप्त होने वाले या समाप्त हैं</li>
        <li><strong>Open Findings</strong> &mdash; अनसुलझे ऑडिट निष्कर्षों की गणना और गंभीरता विवरण</li>
        <li><strong>Policy Review Status</strong> &mdash; समीक्षा के लिए देय नीतियाँ</li>
        <li><strong>Monitor Health</strong> &mdash; continuous monitors की Pass/fail स्थिति</li>
        <li><strong>Risk Register Summary</strong> &mdash; गंभीरता के अनुसार खुले जोखिम</li>
    </ul>

    <h3>कैसे पहुंचें</h3>
    <p><span class="menu-label">GRC Module</span> &rarr; <span class="menu-label">Compliance</span> &rarr; <span class="menu-label">GRC Dashboard</span> पर जाएं।</p>
</div>


<!-- ================================================================
     TPRM MODULE
     ================================================================ -->
<div class="doc-section" id="tprm-overview">
    <h2>TPRM मॉड्यूल: Third Party Risk Management क्या है?</h2>
    <p>हर कंपनी बाहरी विक्रेताओं पर निर्भर करती है &mdash; क्लाउड प्रदाता, पेरोल कंपनियाँ, मार्केटिंग प्लेटफ़ॉर्म, IT सलाहकार। प्रत्येक विक्रेता के पास आपके डेटा या सिस्टम तक पहुंच हो सकती है। <strong>TPRM</strong> आपको उत्तर देने में मदद करता है: "प्रत्येक विक्रेता कितना जोखिम भरा है, और क्या वे हमारे डेटा की सुरक्षा कर रहे हैं?"</p>
    <ul>
        <li>एक स्थान पर अपने सभी विक्रेता जोड़ें और ट्रैक करें</li>
        <li>एक जोखिम टियर असाइन करें (Tier 1 = उच्चतम जोखिम, Tier 3 = सबसे कम)</li>
        <li>विक्रेताओं को सुरक्षा प्रश्नावलियाँ (मूल्यांकन) भेजें</li>
        <li>बाहरी सुरक्षा रेटिंग सेवाओं का उपयोग करके विक्रेताओं को स्वचालित रूप से स्कोर करें</li>
        <li>संभावित वित्तीय नुकसान का अनुमान लगाने के लिए मात्रात्मक जोखिम विश्लेषण (FAIR) करें</li>
        <li>4th-पार्टी जोखिम ट्रैक करें (आपके विक्रेताओं के विक्रेता)</li>
        <li>अप्रबंधित SaaS एप्लिकेशन खोजें (Shadow SaaS)</li>
    </ul>
</div>

<div class="doc-section" id="tprm-add-vendor">
    <h2>नया विक्रेता जोड़ना</h2>
    <ol class="steps">
        <li>बाएं साइडबार में, <span class="menu-label">TPRM Module</span> विस्तारित करें, फिर <span class="menu-label">Stakeholders</span> अनुभाग विस्तारित करें।</li>
        <li><span class="menu-label">New Request</span> पर क्लिक करें। यह विक्रेता ऑनबोर्डिंग फ़ॉर्म खोलता है।</li>
        <li>आवश्यक फ़ील्ड भरें:
            <ul>
                <li><span class="field-label">Vendor Name</span> &mdash; कंपनी का कानूनी नाम (जैसे, "Acme Cloud Services")</li>
                <li><span class="field-label">Vendor Domain</span> &mdash; https:// के बिना उनकी वेबसाइट डोमेन (जैसे, "acmecloud.com")। सुरक्षा स्कोरिंग इंजन द्वारा विक्रेता को स्कैन करने के लिए उपयोग किया जाता है।</li>
            </ul>
        </li>
        <li>अनुशंसित वैकल्पिक फ़ील्ड भरें:
            <ul>
                <li><span class="field-label">Vendor Type</span> &mdash; Technology, Professional Services, Financial Services, HR/Benefits, आदि</li>
                <li><span class="field-label">Vendor Tier</span> &mdash; 1 (Critical), 2 (Important), या 3 (Standard)</li>
                <li><span class="field-label">Primary Contact Name</span>, <span class="field-label">Email</span>, <span class="field-label">Phone</span></li>
                <li><span class="field-label">PII Record Count</span> &mdash; यह विक्रेता कितने व्यक्तिगत रिकॉर्ड तक पहुंचता है</li>
                <li><span class="field-label">SPII Record Count</span> &mdash; कितने संवेदनशील व्यक्तिगत रिकॉर्ड (SSN, स्वास्थ्य डेटा)</li>
            </ul>
        </li>
        <li><span class="btn-label">Save</span> पर क्लिक करें। विक्रेता <strong>Draft</strong> स्थिति में बनाया गया है।</li>
    </ol>

    <div class="callout callout-info">
        <strong>विक्रेता टियर समझाए गए:</strong><br>
        &bull; <strong>Tier 1 (Critical)</strong> &mdash; संवेदनशील डेटा या महत्वपूर्ण सिस्टम तक पहुंच वाले विक्रेता। पूर्ण मूल्यांकन आवश्यक।<br>
        &bull; <strong>Tier 2 (Important)</strong> &mdash; मध्यम पहुंच वाले विक्रेता। मानक मूल्यांकन आवश्यक।<br>
        &bull; <strong>Tier 3 (Standard)</strong> &mdash; कम-जोखिम विक्रेता। केवल एक बुनियादी समीक्षा की आवश्यकता हो सकती है।
    </div>
</div>

<div class="doc-section" id="tprm-lifecycle">
    <h2>विक्रेता जीवनचक्र</h2>
    <p>विक्रेता एक परिभाषित जीवनचक्र से गुजरते हैं:</p>
    <p><span class="status-label">Draft</span> &rarr; <span class="status-label">Pending Review</span> &rarr; <span class="status-label">In Review</span> &rarr; <span class="status-label">Approved</span> (या <span class="status-label">Rejected</span>) &rarr; <span class="status-label">Active</span> &rarr; <span class="status-label">Annual Review</span> &rarr; <span class="status-label">Offboarded</span></p>
    <p>प्रत्येक चरण उचित वर्कफ़्लो, अधिसूचनाएं और आवश्यक क्रियाएं ट्रिगर करता है।</p>
</div>

<div class="doc-section" id="tprm-assessments">
    <h2>विक्रेता मूल्यांकन</h2>
    <p>विक्रेता मूल्यांकन सुरक्षा प्रश्नावलियाँ हैं जो विक्रेताओं को उनकी सुरक्षा स्थिति का मूल्यांकन करने के लिए भेजी जाती हैं। उन्हें प्रबंधित करने के लिए <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Assessments</span> पर जाएं।</p>
    <ol class="steps">
        <li>एक विक्रेता का विवरण पृष्ठ खोलें।</li>
        <li><span class="btn-label">Send Assessment</span> पर क्लिक करें।</li>
        <li>विक्रेता के टियर के लिए उपयुक्त मूल्यांकन टेम्पलेट चुनें।</li>
        <li>विक्रेता को प्रश्नावली पूरी करने के लिए एक लिंक के साथ ईमेल मिलती है।</li>
        <li>सबमिट होने के बाद, विक्रेता की प्रतिक्रियाओं की समीक्षा करें और उन्हें स्कोर करें।</li>
    </ol>
</div>

<div class="doc-section" id="assessment-forms">
    <h2>मूल्यांकन फ़ॉर्म: डाउनलोड, भरें, आयात करें &amp; AI ऑटो-फ़िल <span class="new-badge">2.6.2 में नया</span></h2>
    <p>हर विक्रेता ब्राउज़र में प्रश्नावली का उत्तर देना नहीं चाहता। किसी व्यक्तिगत मूल्यांकन के पृष्ठ से (<span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Assessments</span> &rarr; एक मूल्यांकन खोलें) आप विक्रेता को एक ऑफ़लाइन प्रति दे सकते हैं, एक पूर्ण की गई फ़ाइल वापस ले सकते हैं, या किसी AI प्रदाता को विक्रेता के अपने प्रमाणपत्रों से उत्तर पूर्व-भरने दे सकते हैं। बटन मूल्यांकन के शीर्ष के पास एक पंक्ति में होते हैं।</p>

    <h3>मूल्यांकन को एक भरने योग्य फ़ाइल के रूप में डाउनलोड करें</h3>
    <ul>
        <li><span class="btn-label">Download PDF</span> &mdash; एक भरने योग्य PDF फ़ॉर्म। प्रत्येक प्रश्न एक वास्तविक फ़ॉर्म फ़ील्ड बन जाता है, इसलिए विक्रेता सीधे फ़ाइल में टाइप कर सकता है और बॉक्स टिक कर सकता है।</li>
        <li><span class="btn-label">Download Excel</span> &mdash; एक वास्तविक <code>.xlsx</code> वर्कबुक जिसे Excel, Google Sheets, या LibreOffice में पूरा किया जा सकता है। एकल-विकल्प प्रश्नों को इन-सेल ड्रॉपडाउन मिलते हैं, और सशर्त प्रश्न स्वचालित रूप से धूसर हो जाते हैं जब वे लागू नहीं होते।</li>
    </ul>
    <div class="callout callout-info">
        <strong>भरने योग्य PDF अब केवल Adobe में नहीं, बल्कि किसी भी ब्राउज़र में काम करता है।</strong> चेक बॉक्स में बेक किए गए appearances होते हैं ताकि वे Chrome, Edge और अन्य अंतर्निहित PDF व्यूअर में दिखें और टॉगल हों (पहले वे केवल Adobe Acrobat/Reader में काम करते थे)। एक टाइप-किया-नाम फ़ील्ड जिसका लेबल <strong>&ldquo;SIGNATURE (TYPE FULL NAME)&rdquo;</strong> है, किसी को भी किसी भी व्यूअर में हस्ताक्षर करने देता है; केवल-Adobe डिजिटल-हस्ताक्षर और दिनांक-हस्ताक्षरित फ़ील्ड Acrobat/Reader को छोड़कर छुपे रहते हैं, जो वास्तव में उनका उपयोग कर सकता है।
    </div>

    <h3>एक पूर्ण किया गया मूल्यांकन आयात करें (PDF, Excel, या CSV)</h3>
    <p>जब विक्रेता पूर्ण की गई फ़ाइल वापस भेजता है, तो <span class="btn-label">Import Completed Assessment</span> पर क्लिक करें और इसे अपलोड करें। प्लेटफ़ॉर्म प्रारूप का स्वचालित रूप से पता लगाता है &mdash; एक पूर्ण किया गया <strong>PDF</strong>, <strong>Excel (.xlsx)</strong>, या <strong>CSV</strong> &mdash; और उत्तरों को मूल्यांकन की मौजूदा प्रतिक्रियाओं में मर्ज करता है।</p>
    <div class="callout callout-warning">
        <strong>फ़ाइल का Reference मेल खाना चाहिए।</strong> प्रत्येक डाउनलोड की गई फ़ाइल में एक छुपा हुआ <strong>Reference</strong> (मूल्यांकन की ID) होता है। यदि Reference गायब है या किसी भिन्न मूल्यांकन से संबंधित है, तो आयात अस्वीकृत कर दिया जाता है और कुछ भी नहीं लिखा जाता &mdash; इसलिए उत्तर कभी भी गलत मूल्यांकन पर नहीं आ सकते।
    </div>

    <h3>इसके बजाय एक प्रमाणपत्र है? <span class="new-badge">2.6.2 में नया</span></h3>
    <p>मूल्यांकन पूरा करने वाले विक्रेता को एक शॉर्टकट की पेशकश की जा सकती है: यदि उनके पास एक प्रासंगिक प्रमाणन है, तो वे हर प्रश्न का उत्तर देने के बजाय इसे अपलोड कर सकते हैं। <strong>&ldquo;Do you have a Certificate?&rdquo;</strong> संकेत अब वह <strong>Certificate Upload Instructions</strong> दिखाता है जो टेम्पलेट लेखक ने लिखे थे, इसलिए यह अब केवल ISO 27001 तक सीमित नहीं है &mdash; एक टेम्पलेट एक SOC 2 Type 2, ISO 27001, या किसी अन्य प्रमाणपत्र को आमंत्रित कर सकता है। (टेम्पलेट लेखक यह पाठ Template Builder में सेट करते हैं; देखें <a href="#admin-templates">Assessment Template Builder</a>।)</p>

    <h3>प्रमाणनों से AI ऑटो-फ़िल <span class="new-badge">2.6.2 में नया</span></h3>
    <p>यदि आपके प्रशासक ने एक <a href="#admin-ai">AI प्रदाता</a> कॉन्फ़िगर किया है, तो एक अधिकृत समीक्षक AI को विक्रेता के अपलोड किए गए प्रमाणन दस्तावेज़ पढ़ने और प्रश्नावली पूर्व-भरने दे सकता है। मूल्यांकन पृष्ठ पर <span class="btn-label">&#9889; Auto-Fill from Certifications</span> पर क्लिक करें।</p>
    <figure class="doc-figure">
        <img src="app/docs/assessment-ai-autofill.png" alt="The Auto-Fill from Certifications button on a vendor assessment" loading="lazy">
        <figcaption><strong>Auto-Fill from Certifications.</strong> एक AI प्रदाता कॉन्फ़िगर होने पर, बटन <strong>Download PDF</strong>, <strong>Download Excel</strong>, और <strong>Import Completed Assessment</strong> के साथ दिखाई देता है। यह विक्रेता के वर्तमान प्रमाणन दस्तावेज़ पढ़ता है और उन प्रश्नों को भरता है जिनका वे दस्तावेज़ उत्तर देते हैं।</figcaption>
    </figure>
    <p>जब आप इसे क्लिक करते हैं तो आपको याद दिलाया जाता है: <em>&ldquo;This will analyze the vendor's certification documents and pre-fill unanswered questions. Existing answers will not be changed.&rdquo;</em> फिर AI विक्रेता के प्रमाणपत्रों के माध्यम से काम करता है और रिपोर्ट करता है, उदाहरण के लिए, <em>&ldquo;Filled 12 of 30 unanswered questions.&rdquo;</em> जानने योग्य कुछ बातें:</p>
    <ul>
        <li><strong>केवल वर्तमान प्रमाणपत्रों का उपयोग किया जाता है।</strong> यह विक्रेता के अपलोड किए गए दस्तावेज़ पढ़ता है जिनका प्रकार <em>Certification</em> है और जो <strong>सक्रिय और समाप्त नहीं हुए</strong> हैं (PDF, CSV, और Excel दस्तावेज़; सबसे हाल के कुछ)। एक समाप्त या प्रतिस्थापित प्रमाणपत्र को अनदेखा किया जाता है।</li>
        <li><strong>यह केवल रिक्त स्थान भरता है।</strong> जिन प्रश्नों का आप पहले ही उत्तर दे चुके हैं वे अछूते छोड़ दिए जाते हैं, और यह कभी भी किसी मौजूदा उत्तर को अधिलेखित नहीं करता।</li>
        <li><strong>यह केवल उसी से उत्तर देता है जो दस्तावेज़ वास्तव में कहते हैं।</strong> AI को अनुमान न लगाने का निर्देश दिया जाता है; जो कुछ भी वह दस्तावेज़ों से आत्मविश्वास से समर्थित नहीं कर सकता उसे किसी व्यक्ति द्वारा पूरा करने के लिए अनुत्तरित छोड़ दिया जाता है।</li>
        <li><strong>आप नियंत्रण में रहते हैं।</strong> भरे गए उत्तर सहेजे जाते हैं और पृष्ठ उन्हें दिखाते हुए रीलोड होता है, ताकि मूल्यांकन सबमिट होने से पहले आप किसी भी उत्तर की समीक्षा और परिवर्तन कर सकें।</li>
    </ul>
    <div class="callout callout-info">
        <strong>इसका उपयोग कौन कर सकता है, और यह कब दिखाई देता है।</strong> बटन केवल <strong>Administrators</strong> और <strong>Cyber TPRM</strong> उपयोगकर्ताओं को दिखाया जाता है, केवल तभी जब एक AI प्रदाता सक्षम हो, और केवल तभी जब विक्रेता के पास फ़ाइल पर कम से कम एक वर्तमान प्रमाणन दस्तावेज़ हो। यह पूर्ण किए गए मूल्यांकन पर छुपा होता है।
    </div>
</div>

<div class="doc-section" id="tprm-action-plan">
    <h2>Vendor Action Plan <span class="new-badge">2.6.2 में नया</span></h2>
    <p>किसी विक्रेता के पृष्ठ पर <strong>Action Plan</strong> टैब साइबर टीम को उस विक्रेता के विरुद्ध अनुवर्ती काम शेड्यूल करने देता है &mdash; विक्रेता से संपर्क करें, एक और मूल्यांकन भेजें, वार्षिक समीक्षा बाध्य करें &mdash; एक नियत तिथि, स्वामियों और स्थिति नोट के एक चालू सेट के साथ। एक दैनिक जॉब प्रत्येक क्रिया को उसकी तिथि आने पर फायर करती है और उसे एक ट्रैक किए गए to-do में बदल देती है।</p>
    <p><span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Onboarding</span> &rarr; <span class="menu-label">My Vendors</span> से एक विक्रेता खोलें, फिर <strong>Action Plan</strong> टैब पर क्लिक करें। यह टैब <strong>Administrators</strong> और <strong>Cyber TPRM</strong> उपयोगकर्ताओं के लिए उपलब्ध है।</p>

    <h3>एक क्रिया शेड्यूल करना</h3>
    <ol class="steps">
        <li><strong>Action Plan</strong> टैब पर, <span class="btn-label">+ Create Action</span> पर क्लिक करें।</li>
        <li><span class="field-label">Action</span> चुनें: <strong>Contact Vendor</strong>, <strong>Contact Stakeholder</strong>, <strong>Send Assessment</strong>, या <strong>Force Annual Review</strong>। (यदि आप <strong>Send Assessment</strong> चुनते हैं, तो एक <span class="field-label">Vendor Assessment</span> पिकर दिखाई देता है ताकि आप चुन सकें कि कौन सा टेम्पलेट भेजना है।)</li>
        <li><span class="field-label">Due Date</span> सेट करें &mdash; वह दिन जब क्रिया को फायर होना चाहिए।</li>
        <li><span class="field-label">Assign to (Cyber TPRM)</span> के अंतर्गत, एक या अधिक Cyber TPRM स्वामियों को टिक करें। (यदि कोई नहीं है, तो क्रिया विक्रेता के stakeholder पर वापस चली जाती है।)</li>
        <li>वैकल्पिक रूप से <span class="field-label">Email assigned individuals when this action fires</span> टिक करें, और इसके बजाय विशिष्ट पतों पर भेजने के लिए <span class="field-label">Notification email addresses</span> का उपयोग करें &mdash; अल्पविराम से अलग। असाइनियों के अपने खाता ईमेल का उपयोग करने के लिए इसे खाली छोड़ दें।</li>
        <li>एक <span class="field-label">Description</span> लिखें (यह बनने वाले to-do में ले जाया जाता है), फिर <span class="btn-label">Create Action</span> पर क्लिक करें।</li>
    </ol>

    <h3>जब एक क्रिया फायर होती है तो क्या होता है</h3>
    <p>प्रत्येक क्रिया एक बार फायर होती है, अपनी नियत तिथि पर या उसके बाद। फायर होने से एक लिंक किया गया <strong>Cyber To-Do</strong> बनता है जो इस Action Plan टैब पर वापस deep-link करता है, क्रिया को अंजाम देता है (<strong>Send Assessment</strong> के लिए यह विक्रेता को प्रश्नावली ईमेल करता है; <strong>Force Annual Review</strong> के लिए यह वार्षिक समीक्षा को देय चिह्नित करता है), और &mdash; यदि आपने इसे सक्षम किया है &mdash; स्वामियों या आपके द्वारा सूचीबद्ध पतों को ईमेल करता है।</p>

    <h3>क्रिया स्थितियाँ</h3>
    <p>एक क्रिया इन स्थितियों से गुजरती है:</p>
    <p><span class="status-label">Pending</span> &rarr; <span class="status-label">In Progress</span> (फायर होने पर स्वचालित रूप से सेट) &rarr; <span class="status-label">Completed</span>, या <span class="status-label">Problem</span> यदि फायर होने पर कुछ गलत हुआ, या <span class="status-label">Cancelled</span> यदि आप इसे फायर होने से पहले रद्द करते हैं। आप स्वयं किसी भी समय स्थिति बदल सकते हैं; दैनिक जॉब कभी भी आपके द्वारा सेट की गई स्थिति को अधिलेखित नहीं करती।</p>

    <h3>स्थिति नोट</h3>
    <p>काम आगे बढ़ने पर दिनांकित <strong>Status Notes</strong> जोड़ने के लिए एक क्रिया खोलें। एक नोट टाइप करें और <span class="btn-label">Add Note</span> पर क्लिक करें। आप अपने स्वयं के नोट संपादित या हटा सकते हैं; प्रशासक किसी के भी संपादित या हटा सकते हैं। प्रत्येक create, edit, और delete ऑडिट-लॉग किया जाता है।</p>

    <div class="callout callout-info">
        <strong>&ldquo;Vendor Remediation Schedule&rdquo; जॉब।</strong> देय क्रियाओं को फायर करने वाली दैनिक जॉब को <strong>Vendor Remediation Schedule</strong> कहा जाता है और यह डिफ़ॉल्ट रूप से हर दिन <strong>सुबह 7:00 बजे</strong> चलती है। प्रशासक इसे <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Scheduler</span> पृष्ठ पर सक्षम, अक्षम या पुनः-समय निर्धारित कर सकते हैं। यदि यह बंद रही है, तो अगली बार चलने पर यह पकड़ बना लेती है, इस बीच देय हुई हर चीज़ को फायर करती है।
    </div>
</div>

<div class="doc-section" id="tprm-srs">
    <h2>Security Risk Scorecard (SRS)</h2>
    <p>SRS DNS कॉन्फ़िगरेशन, SSL/TLS, ईमेल सुरक्षा (SPF, DKIM, DMARC), खुले पोर्ट और अन्य तकनीकी संकेतकों के आधार पर प्रत्येक विक्रेता के लिए एक स्वचालित, बाहरी सुरक्षा स्कोर प्रदान करता है।</p>
    <p><span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Security Risk Scorecard</span> पर जाएं।</p>
</div>

<div class="doc-section" id="tprm-fair">
    <h2>FAIR Analysis</h2>
    <p><strong>FAIR</strong> (Factor Analysis of Information Risk) एक मात्रात्मक जोखिम मॉडल है जो किसी विक्रेता को शामिल करने वाली सुरक्षा घटना से संभावित वित्तीय नुकसान का अनुमान लगाता है।</p>
    <p>विश्लेषण बनाने और देखने के लिए <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">FAIR Analysis</span> पर जाएं।</p>
</div>

<div class="doc-section" id="tprm-fourth-party">
    <h2>4th Party Risk</h2>
    <p>उन विक्रेताओं को ट्रैक करें जिन पर <em>आपके विक्रेता</em> निर्भर करते हैं। यदि आपका क्लाउड प्रदाता डेटा स्टोरेज के लिए एक उपठेकेदार का उपयोग करता है, तो वह एक 4th-party जोखिम है। साइडबार से आप <span class="menu-label">4th Party Risk</span> (technology concentration), <span class="menu-label">CVE Search</span>, और <span class="menu-label">Subprocessors</span> खोल सकते हैं। यह प्रशासकों और Cyber TPRM उपयोगकर्ताओं के लिए उपलब्ध है; लेखापरीक्षक देख सकते हैं लेकिन कार्य नहीं कर सकते।</p>

    <h3>Subprocessor concentration <span class="new-badge">2.6.2 में नया</span></h3>
    <p><strong>Subprocessor Concentration</strong> दृश्य देखने के लिए <span class="menu-label">Subprocessors</span> खोलें: हर subprocessor जिसे आपके विक्रेताओं ने घोषित किया है, और आपके कितने विक्रेता प्रत्येक का उपयोग करते हैं। कई विक्रेताओं में साझा किया गया एक subprocessor हाइलाइट किया जाता है &mdash; वह साझा निर्भरता आपूर्ति-श्रृंखला concentration जोखिम है। (Subprocessors को किसी विक्रेता में उस विक्रेता के विवरण पृष्ठ से जोड़ा जाता है।)</p>

    <h3>किसी subprocessor का उपयोग करने वाले सभी को मूल्यांकन भेजें <span class="new-badge">2.6.2 में नया</span></h3>
    <p>जब एक subprocessor जोखिम केंद्रित करता है, तो आप एक क्रिया में उस पर निर्भर विक्रेताओं का सर्वेक्षण कर सकते हैं:</p>
    <ol class="steps">
        <li>Subprocessors सूची पर, उस subprocessor की पंक्ति पर <span class="btn-label">Send Assessment</span> पर क्लिक करें।</li>
        <li>विक्रेता पिकर में, चुनें कि उस subprocessor का उपयोग करने वाले किन विक्रेताओं को मूल्यांकन मिलना चाहिए (या <span class="field-label">Select All Visible</span>), फिर जारी रखें।</li>
        <li>एक <span class="field-label">Assessment Template</span> और एक <span class="field-label">Expires In</span> विंडो (14, 30, 60, या 90 दिन) चुनें, फिर <span class="btn-label">Assign Assessment</span> पर क्लिक करें।</li>
    </ol>
    <p>प्रत्येक चयनित विक्रेता को प्रश्नावली (एक request-for-information) ईमेल की जाती है, और एक अनुस्मारक ट्रैक किया जाता है ताकि अनुवर्ती स्वचालित रूप से जाएं। फ़ाइल पर कोई ईमेल न रखने वाले विक्रेता छोड़ दिए जाते हैं, और कोई भी विफल होने वाला send अनुस्मारक जॉब द्वारा पुनः प्रयास किया जाता है। वही <strong>Assign Assessment</strong> प्रवाह technology-concentration और CVE दृश्यों से भी उपलब्ध है।</p>
</div>

<div class="doc-section" id="tprm-shadow-saas">
    <h2>Shadow SaaS खोज</h2>
    <p>अपने संगठन में उपयोग किए जा रहे SaaS एप्लिकेशन खोजें जिन्हें औपचारिक रूप से अनुमोदित या मूल्यांकन नहीं किया गया हो। <span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Modules</span> &rarr; <span class="menu-label">Shadow SaaS</span> पर जाएं। v2.6.2 में यह सूची <a href="#shadow-saas-grip">Grip</a> या <a href="#shadow-saas-hero">Hero</a> Shadow SaaS एकीकरण द्वारा स्वचालित रूप से भरी जा सकती है, और अनुचित ऐप्स को <a href="#zscaler">Zscaler</a> में ब्लॉक किया जा सकता है।</p>
</div>


<!-- ================================================================
     VENDOR ONBOARDING & PROCUREMENT (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="onboarding-workflow">
    <h2>विक्रेता ऑनबोर्डिंग &amp; Procurement Onboarding <span class="new-badge">2.6.2 में नया</span></h2>
    <p>एक <strong>vendor onboarding request</strong> वह तरीका है जिससे एक नया विक्रेता प्लेटफ़ॉर्म में प्रवेश करता है। यह पहले मसौदे से अंतिम निर्णय तक कई <strong>स्थितियों</strong> से गुजरता है। इससे पहले कि साइबर टीम किसी विक्रेता की समीक्षा करे, विक्रेता को पहले <strong>आपकी खरीद प्रक्रिया के माध्यम से ऑनबोर्ड किया जाना चाहिए</strong> और उसके पास एक वैध <strong>Vendor ID (VID)</strong> होनी चाहिए। यह अनुभाग बताता है क्यों, और यह बिल्कुल कैसे काम करता है।</p>

    <h3>ऑनबोर्डिंग यात्रा (स्थितियाँ)</h3>
    <table class="doc-table">
        <tr><th>स्थिति</th><th>इसका क्या अर्थ है</th></tr>
        <tr><td><span class="status-label">Draft</span></td><td>अनुरोध भरा जा रहा है। इसे अभी समीक्षा के लिए नहीं भेजा गया है।</td></tr>
        <tr><td><span class="status-label">Submitted</span></td><td>अनुरोध सबमिशन जांच पास कर गया और साइबर टीम को भेजा गया है।</td></tr>
        <tr><td><span class="status-label">In Review</span></td><td>साइबर टीम विक्रेता की समीक्षा कर रही है।</td></tr>
        <tr><td><span class="status-label">AI Review</span></td><td>विक्रेता की सेवाएं AI का उपयोग करती हैं और यह समर्पित AI समीक्षा चरण में है (देखें <a href="#ai-review">AI Review</a>)।</td></tr>
        <tr><td><span class="status-label">Evaluation</span></td><td>विक्रेता का परीक्षण या मूल्यांकन किया जा रहा है।</td></tr>
        <tr><td><span class="status-label">Approved</span></td><td>विक्रेता को अनुमोदित किया गया है और ऑनबोर्ड किया गया है।</td></tr>
        <tr><td><span class="status-label">Rejected</span></td><td>विक्रेता को अनुमोदित नहीं किया गया।</td></tr>
        <tr><td><span class="status-label">Inactive</span></td><td>विक्रेता अब सक्रिय नहीं है।</td></tr>
    </table>

    <h3>अपने विक्रेता अनुरोध खोजना</h3>
    <p><span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Stakeholders</span> &rarr; <span class="menu-label">Vendor Onboarding</span> पर जाएं। आपको उनकी स्थिति, टियर, सुरक्षा स्कोर (SRS), और त्वरित क्रियाओं (View, Edit) के साथ विक्रेताओं की एक खोज योग्य सूची दिखाई देगी। सूची को सीमित करने के लिए शीर्ष पर फ़िल्टर पिल्स का उपयोग करें (उदाहरण के लिए <strong>All</strong>, <strong>Approved</strong>, <strong>Review</strong>)। एक नया विक्रेता शुरू करने के लिए <span class="btn-label">+ New Request</span> का उपयोग करें।</p>
    <figure class="doc-figure">
        <img src="app/docs/vendor-onboarding-list.png" alt="Vendor Onboarding Requests list" loading="lazy">
        <figcaption><strong>Vendor Onboarding सूची।</strong> खोज, फ़िल्टर पिल्स और प्रति-विक्रेता क्रियाएं। <strong>Review</strong> फ़िल्टर पिल एक एकल दृश्य है जो <em>In Review</em> और <em>AI Review</em> दोनों विक्रेताओं को जोड़ता है।</figcaption>
    </figure>

    <h3 id="procurement-onboarding">समीक्षा से पहले प्रत्येक विक्रेता को जिन दो चीज़ों की आवश्यकता है</h3>
    <p>एक विक्रेता खोलें और <strong>Vendor Information</strong> कार्ड देखें। दो फ़ील्ड यह नियंत्रित करते हैं कि विक्रेता को साइबर समीक्षा के लिए सबमिट किया जा सकता है या नहीं:</p>
    <ul>
        <li><strong>Procurement Onboarding</strong> &mdash; एक Yes/No फ़ील्ड जो प्रश्न का उत्तर देता है <em>"क्या इस विक्रेता ने Procurement Onboarding पूरी कर ली है?"</em> इसे <strong>Yes</strong> पर सेट किया जाना चाहिए।</li>
        <li><strong>Vendor ID (VID)</strong> &mdash; आपके खरीद प्रणाली द्वारा विक्रेता को असाइन किया गया 4&ndash;8 अंकीय पहचानकर्ता। यह एक वैध 4&ndash;8 अंकीय संख्या होनी चाहिए।</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/vendor-info-card.png" alt="Vendor Information card showing Procurement Onboarding and Vendor ID fields" loading="lazy">
        <figcaption><strong>Vendor Information कार्ड।</strong> विक्रेता सबमिट होने से पहले <strong>Vendor ID (VID)</strong> और procurement-onboarding फ़ील्ड दोनों भरे होने चाहिए। <em>नोट:</em> पहले के रिलीज़ से अपग्रेड किए गए इंस्टेंस पर यह फ़ील्ड अभी भी <strong>"VSU Onboarded"</strong> पढ़ सकती है; v2.6.2 में इसे <strong>"Procurement Onboarding"</strong> लेबल किया गया है &mdash; यह वही फ़ील्ड है।</figcaption>
    </figure>

    <h3>समीक्षा के लिए विक्रेता सबमिट करना</h3>
    <ol class="steps">
        <li><span class="menu-label">Vendor Onboarding</span> सूची से विक्रेता खोलें (अनुरोध <strong>Draft</strong> में होना चाहिए)।</li>
        <li><strong>Vendor Information</strong> कार्ड में, <span class="field-label">Procurement Onboarding</span> को <strong>Yes</strong> पर सेट करें और एक वैध <span class="field-label">Vendor ID (VID)</span> (4&ndash;8 अंक) दर्ज करें। अपने परिवर्तन सहेजें।</li>
        <li><span class="btn-label">Submit for Review</span> पर क्लिक करें। आपसे पुष्टि करने के लिए कहा जाएगा: <em>"Submit this vendor for review? The vendor must have a valid VID and be onboarded at VSU."</em></li>
        <li>यदि दोनों जांच पास होती हैं, तो स्थिति <strong>Submitted</strong> में बदल जाती है और साइबर टीम को सूचित किया जाता है।</li>
    </ol>
    <div class="callout callout-danger">
        <strong>यदि सबमिशन ब्लॉक हो,</strong> तो आपको इनमें से एक संदेश दिखाई देगा:
        <ul style="margin:8px 0 0;">
            <li>"Cannot submit: Vendor must be onboarded at VSU before submission. Please complete the onboarding assessment with VSU details." &rarr; <strong>Procurement Onboarding</strong> को <strong>Yes</strong> पर सेट करें।</li>
            <li>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits). Please complete the onboarding assessment with the VSU Vendor ID." &rarr; एक वैध 4&ndash;8 अंकीय <strong>Vendor ID</strong> दर्ज करें।</li>
        </ul>
        यह नियम क्यों मौजूद है इसके लिए <a href="#troubleshooting">Troubleshooting</a> देखें।
    </div>
</div>

<div class="doc-section" id="custom-onboarding">
    <h2>कस्टम ऑनबोर्डिंग फ़ील्ड &amp; Custom Data टैब <span class="new-badge">2.6.2 में नया</span></h2>
    <p>मानक विक्रेता फ़ील्ड (नाम, डोमेन, टियर, VAT, इत्यादि) अधिकांश जरूरतों को कवर करते हैं, लेकिन हर संगठन कुछ अतिरिक्त ट्रैक करता है। v2.6.2 में एक ऑनबोर्डिंग टेम्पलेट <strong>कस्टम फ़ील्ड</strong> परिभाषित कर सकता है जिनका कोई मानक विक्रेता कॉलम नहीं है। उनके मान प्रति विक्रेता कैप्चर किए जाते हैं और विक्रेता के <strong>Custom Data</strong> टैब पर दिखाए जाते हैं।</p>

    <h3>कस्टम मान कहाँ रहते हैं: Custom Data टैब</h3>
    <p>एक विक्रेता खोलें (<span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Vendor Onboarding</span> &rarr; <span class="menu-label">My Vendors</span> &rarr; एक विक्रेता खोलें)। यदि विक्रेता का ऑनबोर्डिंग टेम्पलेट कोई कस्टम फ़ील्ड परिभाषित करता है, तो अन्य विक्रेता टैब के साथ एक <strong>Custom Data</strong> टैब दिखाई देता है, जिसमें फ़ाइल पर कितने कस्टम मान हैं इसकी गणना होती है। टैब तब तक केवल-पढ़ने योग्य है जब तक आप <span class="btn-label">Edit</span> पर क्लिक नहीं करते; अपने परिवर्तन करें और <span class="btn-label">Save Custom Data</span> पर क्लिक करें। फ़ील्ड उनके टेम्पलेट सेक्शन के अनुसार समूहीकृत होते हैं। जिन उपयोगकर्ताओं को किसी फ़ील्ड को देखने की अनुमति है लेकिन संपादित करने की नहीं, वे इसे <em>(view only)</em> चिह्नित देखते हैं।</p>

    <h3>एक कस्टम फ़ील्ड परिभाषित करना (प्रशासक)</h3>
    <p>एक कस्टम फ़ील्ड बस एक <strong>Onboarding</strong>-श्रेणी टेम्पलेट पर एक प्रश्न है जिसका <span class="field-label">Field Name</span> ऐसा है जो एक अंतर्निहित विक्रेता कॉलम <em>नहीं</em> है। दो चरण हैं, दोनों Admin Portal में:</p>
    <ol class="steps">
        <li><strong>फ़ील्ड नाम पंजीकृत करें।</strong> <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Field Reference</span> पर जाएं, <span class="btn-label">+ Add Field</span> पर क्लिक करें, और अपना कस्टम फ़ील्ड जोड़ें (छोटे अक्षर, संख्याएं और अंडरस्कोर; जैसे <code>data_residency_region</code>)। एक कॉलम प्रकार (text, number, date, आदि) और <span class="field-label">Onboarding</span> श्रेणी चुनें।</li>
        <li><strong>एक प्रश्न जोड़ें जो इससे मैप हो।</strong> <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Template Builder</span> में, अपना ऑनबोर्डिंग टेम्पलेट खोलें, एक प्रश्न जोड़ें, और उसका <span class="field-label">Field Name</span> उस फ़ील्ड पर सेट करें जिसे आपने अभी पंजीकृत किया है। देखें <a href="#admin-templates">Assessment Template Builder</a>।</li>
    </ol>

    <h3>समृद्ध उत्तरों के लिए नए फ़ील्ड प्रकार <span class="new-badge">2.6.2 में नया</span></h3>
    <p>मौजूदा text, number, date, dropdown, और radio प्रकारों से परे, प्रश्न (कस्टम या मानक) अब इनका उपयोग कर सकते हैं:</p>
    <table class="doc-table">
        <tr><th>प्रकार</th><th>विक्रेता क्या देखता है</th></tr>
        <tr><td><strong>Checkboxes</strong></td><td>एक multi-select सूची &mdash; लागू होने वाला हर विकल्प टिक करें।</td></tr>
        <tr><td><strong>Button Group (Multi)</strong></td><td>वही multi-select, टॉगल बटन की एक पंक्ति के रूप में दिखाया गया।</td></tr>
        <tr><td><strong>Phone</strong></td><td>देश-कोड &amp; फ्लैग पिकर वाला एक फ़ोन नंबर (देखें <a href="#question-types">Phone &amp; VAT प्रश्न प्रकार</a>)।</td></tr>
        <tr><td><strong>VAT Number</strong></td><td>डबल एंट्री और लाइव VIES सत्यापन वाला एक EU VAT नंबर (देखें <a href="#question-types">Phone &amp; VAT प्रश्न प्रकार</a>)।</td></tr>
    </table>
    <p>एकल-चयन समकक्ष (<strong>Dropdown</strong>, <strong>Radio Buttons</strong>, <strong>Button Group</strong>) अभी भी उपलब्ध हैं। Multi-select प्रकारों को एक <span class="field-label">Options</span> सूची (प्रति पंक्ति एक) की आवश्यकता होती है।</p>

    <h3>कौन एक फ़ील्ड देख और संपादित कर सकता है यह नियंत्रित करना (भूमिका-आधारित गेटिंग) <span class="new-badge">2.6.2 में नया</span></h3>
    <p>ऑनबोर्डिंग टेम्पलेट पर, प्रत्येक <strong>कस्टम</strong> सेक्शन और प्रश्न दो भूमिका नियंत्रण रखता है, ताकि आप संवेदनशील फ़ील्ड को उन लोगों से दूर रख सकें जिन्हें उन्हें नहीं देखना चाहिए:</p>
    <ul>
        <li><span class="field-label">Visible to Roles</span> &mdash; कौन सी भूमिकाएं फ़ील्ड <em>देख</em> सकती हैं।</li>
        <li><span class="field-label">Visible and Editable Roles</span> &mdash; कौन सी भूमिकाएं इसे <em>संपादित</em> कर सकती हैं।</li>
    </ul>
    <div class="callout callout-info">
        <strong>कस्टम फ़ील्ड डिफ़ॉल्ट रूप से निजी होते हैं।</strong> एक मानक प्रश्न के विपरीत, एक कस्टम फ़ील्ड तब तक छुपा रहता है जब तक आप एक भूमिका प्रदान नहीं करते। जब तक कोई भूमिका प्रदान नहीं की जाती, केवल super administrators इसे देख या संपादित कर सकते हैं। जिस फ़ील्ड को एक दर्शक को देखने की अनुमति नहीं है, उसे उस व्यक्ति के लिए Custom Data टैब, विक्रेता पृष्ठ, CSV निर्यात और API से बाहर छोड़ दिया जाता है। (यह grant-only डिफ़ॉल्ट कस्टम फ़ील्ड पर लागू होता है; मानक ऑनबोर्डिंग प्रश्न इस तरह कभी गेट नहीं किए जाते।)
    </div>
    <p>किसी व्यक्ति द्वारा एक प्रश्न देखने से पहले सेक्शन के grant और प्रश्न के grant दोनों को उस व्यक्ति को अनुमति देनी चाहिए, इसलिए आप एक पूरा सेक्शन या उसके भीतर सिर्फ व्यक्तिगत फ़ील्ड छुपा सकते हैं।</p>

    <h3>निर्यात और API में कस्टम मान</h3>
    <ul>
        <li><strong>CSV निर्यात।</strong> <span class="menu-label">Vendor Onboarding</span> सूची पर, <span class="btn-label">Export CSV</span> (प्रशासक और Cyber TPRM) अब मानक कॉलम के साथ-साथ प्रति कस्टम फ़ील्ड एक कॉलम जोड़ता है, जिसका नाम <code>custom:&lt;field_name&gt;</code> है।</li>
        <li><strong>REST API.</strong> एकल-विक्रेता प्रतिक्रिया (<code>GET /vendors/{id}</code>) में एक <code>custom_onboarding_data</code> array शामिल है; प्रत्येक प्रविष्टि में <code>field_name</code>, <code>label</code>, <code>value</code>, <code>type</code>, <code>section</code>, और <code>template_name</code> हैं।</li>
    </ul>
    <p>दोनों Custom Data टैब के समान स्थान से पढ़ते हैं और समान भूमिका-आधारित दृश्यता का सम्मान करते हैं &mdash; जिस फ़ील्ड को कॉलर (या API कुंजी का स्वामी) नहीं देख सकता उसे रिक्त या छोड़ दिया जाता है।</p>
</div>

<div class="doc-section" id="ai-review">
    <h2>विक्रेताओं के लिए AI Review <span class="new-badge">2.6.2 में नया</span></h2>
    <p>कुछ विक्रेता ऐसी सेवाएं प्रदान करते हैं जो कृत्रिम बुद्धिमत्ता का उपयोग करती हैं। इन विक्रेताओं में अलग-अलग जोखिम हो सकते हैं, इसलिए v2.6.2 समीक्षा प्रक्रिया के दौरान उन्हें अलग से ट्रैक करने के लिए एक समर्पित <strong>AI Review</strong> स्थिति जोड़ता है।</p>

    <h3>एक विक्रेता AI Review में कैसे प्रवेश करता है</h3>
    <p><strong>Vendor Information</strong> कार्ड पर एक <span class="field-label">Services Use AI</span> फ़ील्ड है। जब इसे <strong>Yes</strong> पर सेट किया जाता है, तो एक अधिकृत समीक्षक (एक <strong>Cyber TPRM</strong> उपयोगकर्ता या <strong>Administrator</strong>, विक्रेता संपादित करते समय) उस फ़ील्ड के ठीक नीचे एक <span class="btn-label">Force AI Review</span> लिंक देखता है।</p>
    <ol class="steps">
        <li>विक्रेता खोलें और पुष्टि करें कि <span class="field-label">Services Use AI</span> <strong>Yes</strong> पर सेट है।</li>
        <li><span class="btn-label">Force AI Review</span> पर क्लिक करें। संकेत की पुष्टि करें: <em>"Force this vendor into AI Review?"</em></li>
        <li>विक्रेता की स्थिति <strong>AI Review</strong> में बदल जाती है।</li>
    </ol>
    <div class="callout callout-info">
        <strong>लिंक क्यों नहीं दिख सकता?</strong> <strong>Force AI Review</strong> लिंक केवल तभी दिखता है जब (1) आपके पास अनुमोदित करने की अनुमति है, (2) आप संपादन मोड में हैं, (3) <strong>Services Use AI</strong> <strong>Yes</strong> है, और (4) विक्रेता पहले से AI Review में नहीं है। यदि <strong>Services Use AI</strong> "No" है, तो आपको संदेश दिखाई देगा <em>"AI Review can only be forced for vendors whose services use AI."</em></p>
    </div>
    <p><a href="#procurement-cyber-status">Procurement Cyber Status</a> पृष्ठ पर और विक्रेता सूची के <strong>Review</strong> फ़िल्टर पर, <strong>In Review</strong> और <strong>AI Review</strong> में विक्रेता एक साथ दिखाए जाते हैं &mdash; इसलिए समीक्षा में कुछ भी कभी छुपाया नहीं जाता सिर्फ इसलिए कि इसे AI के साथ समीक्षा किया जा रहा है।</p>
</div>

<div class="doc-section" id="procurement-cyber-status">
    <h2>Procurement Cyber Status <span class="new-badge">2.6.2 में नया</span></h2>
    <p><strong>Cyber Status</strong> पृष्ठ <strong>खरीद टीम</strong> को एक सरल, हमेशा-वर्तमान दृश्य देता है कि साइबर टीम किन विक्रेताओं की समीक्षा कर रही है और प्रत्येक पर नवीनतम क्या है &mdash; पूर्ण सुरक्षा टूलिंग तक पहुंच की आवश्यकता के बिना। साइबर टीम संक्षिप्त, दिनांकित अपडेट पोस्ट करती है; खरीद यहाँ (और एक साप्ताहिक ईमेल में) उन्हें पढ़ती है।</p>
    <p><span class="menu-label">TPRM Module</span> &rarr; <span class="menu-label">Procurement</span> &rarr; <span class="menu-label">Cyber Status</span> से इसे खोलें। यह <strong>Procurement</strong>, <strong>Cyber TPRM</strong>, और <strong>Administrator</strong> उपयोगकर्ताओं के लिए उपलब्ध है।</p>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status.png" alt="Procurement Cyber Status page" loading="lazy">
        <figcaption><strong>Procurement &rarr; Cyber Status.</strong> हर उस विक्रेता को सूचीबद्ध करता है जिसकी स्थिति <em>In Review</em> या <em>AI Review</em> है, अपडेट की संख्या और नवीनतम अपडेट की तिथि के साथ। जब कोई विक्रेता समीक्षा में नहीं होता तो तालिका को "No vendors in review" संदेश से बदल दिया जाता है।</figcaption>
    </figure>

    <h3>एक विक्रेता का अपडेट इतिहास पढ़ना</h3>
    <ol class="steps">
        <li><strong>Vendors in Review</strong> तालिका में एक विक्रेता का नाम क्लिक करें।</li>
        <li><strong>Procurement Update History</strong> पैनल खुलता है, जो नवीनतम-पहले प्रत्येक अपडेट दिखाता है: दिनांक और समय, किसने लिखा, उस समय विक्रेता की स्थिति, और नोट स्वयं।</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status-updates.png" alt="Procurement Update History for a vendor in review" loading="lazy">
        <figcaption><strong>एक विक्रेता का अपडेट इतिहास।</strong> एक विक्रेता नाम क्लिक करने से उसका <strong>Procurement Update History</strong> खुलता है। प्रत्येक प्रविष्टि दिनांक और समय, लेखक, नोट लिखे जाने पर विक्रेता की स्थिति के लिए एक बैज और साइबर टीम का नोट दिखाती है &mdash; ताकि खरीद देख सके कि प्रत्येक समीक्षा कहाँ खड़ी है। लंबे इतिहास <em>Show&nbsp;per&nbsp;page</em> नियंत्रण के साथ पेज किए जाते हैं।</figcaption>
    </figure>

    <h3>साइबर समीक्षकों के लिए: खरीद को अपडेट पोस्ट करना</h3>
    <p>Cyber TPRM उपयोगकर्ता और एडमिन एक बार में एक या अधिक विक्रेताओं के लिए अपडेट पोस्ट कर सकते हैं:</p>
    <ol class="steps">
        <li><strong>Cyber Status</strong> पृष्ठ पर, प्रत्येक विक्रेता के बगल में चेकबॉक्स टिक करें जिसे आप अपडेट करना चाहते हैं।</li>
        <li><span class="btn-label">Provide Procurement with Update</span> पर क्लिक करें।</li>
        <li><strong>Provide Procurement with Update</strong> विंडो में, <span class="field-label">Update</span> बॉक्स में अपना नोट टाइप करें।</li>
        <li>वैकल्पिक रूप से <span class="field-label">Change status</span> का उपयोग करके विक्रेता(ओं) को आगे बढ़ाएं (उदाहरण के लिए <strong>Evaluation</strong>, <strong>Approved</strong>, या <strong>Rejected</strong> पर)। केवल एक नोट जोड़ने के लिए इसे <em>Keep current status</em> पर छोड़ दें।</li>
        <li><span class="btn-label">Save Update</span> पर क्लिक करें। अपडेट प्रत्येक चुने गए विक्रेता के विरुद्ध रिकॉर्ड किया जाता है।</li>
    </ol>

    <h3>साप्ताहिक खरीद डाइजेस्ट ईमेल</h3>
    <p>खरीद को बिना किसी के लॉग इन किए सूचित रखने के लिए, प्लेटफ़ॉर्म समीक्षा में प्रत्येक विक्रेता को उसके सबसे हालिया अपडेट के साथ सूचीबद्ध करने वाला एक <strong>साप्ताहिक डाइजेस्ट</strong> ईमेल कर सकता है। डिफ़ॉल्ट रूप से यह <strong>प्रत्येक सोमवार सुबह 7:00 बजे</strong> भेजा जाता है।</p>
    <ol class="steps">
        <li>एक प्रशासक <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email Settings</span> पर जाता है और <strong>Procurement Update Digest</strong> विकल्प खोजता है।</li>
        <li>डाइजेस्ट <strong>चालू</strong> करें और एक या अधिक प्राप्तकर्ता ईमेल पते दर्ज करें (अल्पविराम से अलग)।</li>
        <li>सहेजें। आप इसे तुरंत परीक्षण करने के लिए <span class="btn-label">Send digest now</span> से एक तुरंत भी भेज सकते हैं।</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-scheduler.png" alt="Admin Scheduler showing the Procurement Update Digest job" loading="lazy">
        <figcaption><strong>Admin &rarr; Scheduler.</strong> <strong>Procurement Update Digest</strong> जॉब (सूची के नीचे) साप्ताहिक चलती है। Scheduler वह जगह है जहां एडमिन सभी स्वचालित जॉब सक्षम, अक्षम और समय निर्धारित करते हैं।</figcaption>
    </figure>
</div>

<!-- ================================================================
     INTEGRATIONS: GRIP + ZSCALER (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="shadow-saas-grip">
    <h2>Grip Shadow SaaS Integration <span class="new-badge">2.6.2 में नया</span></h2>
    <p>"Shadow SaaS" का अर्थ है क्लाउड ऐप जिन्हें कर्मचारी उपयोग करते हैं जिन्हें कभी औपचारिक रूप से अनुमोदित नहीं किया गया। <strong>Grip Security</strong> एक सेवा है जो इन ऐप्स को खोजती है। v2.6.2 में आप अपना Grip खाता कनेक्ट कर सकते हैं ताकि प्लेटफ़ॉर्म स्वचालित रूप से Grip द्वारा खोजे गए ऐप्स लाए &mdash; साथ में कितने लोग प्रत्येक का उपयोग करते हैं, एक जोखिम स्कोर और सुरक्षा अलर्ट &mdash; और उन्हें आपके <a href="#tprm-shadow-saas">Shadow SaaS</a> पृष्ठ पर सूचीबद्ध करें।</p>
    <p>इसे एक प्रशासक द्वारा <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> पर <strong>Grip</strong> टैब पर कॉन्फ़िगर किया जाता है। Grip दो Shadow SaaS प्रदाताओं में से एक है (दूसरा <a href="#shadow-saas-hero">Hero</a> है); एक समय में केवल एक सक्षम हो सकता है।</p>

    <h3>Grip कनेक्ट करना (चरण दर चरण)</h3>
    <ol class="steps">
        <li>Grip में, एक <strong>API token</strong> बनाएं और अपने tenant का बेस URL नोट करें (यह <code>/public/saas</code> में समाप्त होता है, उदाहरण के लिए <code>https://tenant.dep.grip.security/public/saas</code>)।</li>
        <li>प्लेटफ़ॉर्म में, <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> पर जाएं और <strong>Grip Security Connection</strong> कार्ड खोजें।</li>
        <li><span class="field-label">Enable Grip Security integration</span> टिक करें।</li>
        <li>अपना tenant URL <span class="field-label">Server (Tenant Base URL)</span> में और अपना token <span class="field-label">API Token</span> में पेस्ट करें।</li>
        <li><span class="btn-label">Save Configuration</span> पर क्लिक करें, फिर पुष्टि करने के लिए <span class="btn-label">Test Connection</span> पर क्लिक करें। एक सफलता संदेश इस तरह दिखता है <em>"Connected to Grip — sample returned 1 record(s)"</em>।</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grip-card.png" alt="Grip Security Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Grip Security Connection.</strong> अपना tenant URL और API token दर्ज करें, सहेजें, फिर परीक्षण करें।</figcaption>
    </figure>

    <h3>इसे स्वचालित रूप से अद्यतन रखना</h3>
    <p>एक शेड्यूल पर सक्षम प्रदाता का डेटा ताज़ा करने के लिए साझा <strong>Scheduled Rehydration</strong> कार्ड (प्रदाता टैब के नीचे) का उपयोग करें। <span class="field-label">Enable scheduled rehydration</span> टिक करें और एक <span class="field-label">Schedule (cron expression)</span> दर्ज करें &mdash; उदाहरण के लिए रात 2&nbsp;बजे दैनिक के लिए <code>0 2 * * *</code>; कार्ड आपके टाइप किए गए का सादा-अंग्रेजी सारांश दिखाता है। जॉब स्वचालित रूप से सिस्टम शेड्यूलर में इंस्टॉल होती है (कोई मैनुअल सर्वर चरण नहीं) और पुनरारंभ के बाद भी जीवित रहती है। आप तुरंत ताज़ा करने के लिए <span class="btn-label">Run Now</span> भी क्लिक कर सकते हैं। वही शेड्यूल वर्तमान में सक्षम किसी भी प्रदाता (Grip या Hero) की सेवा करता है।</p>

    <h3>बाद में आप क्या देखेंगे</h3>
    <p>खोजे गए ऐप्स <span class="menu-label">Shadow SaaS</span> पृष्ठ पर <strong>Pending</strong> प्रविष्टियों के रूप में दिखाई देते हैं जिनमें एक जोखिम स्कोर (1&ndash;5 पैमाने पर दिखाया गया), श्रेणी और उपयोगकर्ताओं की संख्या है। वहाँ से आप एक ऐप को <strong>Allow</strong> कर सकते हैं (जो इसे एक विक्रेता के रूप में ऑनबोर्ड करना शुरू करता है), <strong>Deny</strong> करें (इसे अनुचित चिह्नित करें, और वैकल्पिक रूप से Zscaler में ब्लॉक करें), या <strong>Dismiss</strong> करें।</p>
    <figure class="doc-figure">
        <img src="app/docs/shadow-saas.png" alt="Shadow SaaS list page" loading="lazy">
        <figcaption><strong>Shadow SaaS पृष्ठ।</strong> अपने जोखिम और क्रियाओं के साथ खोजे गए और आयातित ऐप्स। विक्रेताओं के रूप में ऑनबोर्ड किए गए ऐप्स भविष्य के sync पर छोड़ दिए जाते हैं, और जो कुछ भी आप dismiss करते हैं वह dismissed रहता है।</figcaption>
    </figure>

    <h3>Live बनाम Local (कैश्ड) डेटा स्रोत <span class="new-badge">2.6.2 में नया</span></h3>
    <p>Grip Security Connection कार्ड पर, <span class="field-label">Data source</span> नियंत्रित करता है कि Grip पृष्ठ कहाँ से पढ़ते हैं:</p>
    <ul>
        <li><strong>Live</strong> &mdash; प्रत्येक पृष्ठ के लिए Grip API को कॉल करता है। हमेशा वर्तमान, लेकिन API पर भारी।</li>
        <li><strong>Local (Hydrated/cached)</strong> &mdash; प्लेटफ़ॉर्म के डेटाबेस में रखी गई Grip डेटा की प्रति से सेवा देता है। API पर हल्का। Local मोड में प्रत्येक sync उस प्रति को <strong>पूरी तरह ताज़ा</strong> करता है; sync के बीच पृष्ठ Grip को कॉल करने के बजाय स्नैपशॉट से सेवा देते हैं।</li>
    </ul>

    <h3>एक sync को देखना और नियंत्रित करना <span class="new-badge">2.6.2 में नया</span></h3>
    <p>जब एक sync चल रहा होता है, तो <strong>Last Sync</strong> कार्ड एक लाइव प्रगति रीडआउट दिखाता है &mdash; <em>&ldquo;Hydrating per-app rosters &mdash; NN% (D / T apps)&rdquo;</em> &mdash; एक <span class="btn-label">Stop Sync</span> बटन के ऊपर जो चल रहे रन को सहयोगात्मक रूप से रद्द करता है। स्थानीय रूप से परोसे गए Grip डेटा को पूरी तरह मिटाने के लिए, उसी कार्ड पर <span class="btn-label">Truncate Data</span> का उपयोग करें: यह Grip मिरर तालिकाओं, Shadow SaaS सूची पर Grip पंक्तियों, और विक्रेता रिकॉर्ड पर स्टैंप किए गए Grip टेलीमेट्री (SaaS Data टैब) को साफ़ करता है। आपका sync इतिहास रखा जाता है, और अगला sync Grip से सब कुछ फिर से हाइड्रेट करता है।</p>

    <h3>SecurityScorecard (SSC) रेटिंग <span class="new-badge">2.6.2 में नया</span></h3>
    <p>जब Grip कनेक्ट होता है, तो एक <strong>SSC</strong> कॉलम Shadow SaaS सूची पर और विक्रेता SRS सूची पर, और विक्रेता के SaaS Data टैब पर प्रत्येक ऐप या विक्रेता का <strong>SecurityScorecard</strong> अक्षर ग्रेड (A&ndash;F) दिखाता है। यह केवल तभी दिखाई देता है जब Grip सक्षम हो।</p>

    <h3>विक्रेता &ldquo;SaaS Data&rdquo; टैब <span class="new-badge">2.6.2 में नया</span></h3>
    <p>जब एक विक्रेता किसी Grip-खोजे गए ऐप से मेल खाता है, तो उस विक्रेता के पृष्ठ पर एक केवल-पढ़ने योग्य <strong>SaaS Data</strong> टैब दिखाई देता है, जो विक्रेता को छोड़े बिना sync के दौरान एकत्र किए गए Grip टेलीमेट्री को सामने लाता है: <strong>First Discovered</strong>, <strong>Active Accounts</strong> (प्रभावित-उपयोगकर्ता सूची में एक लिंक), <strong>Last Known Usage</strong>, ऐप वर्गीकरण, <strong>Security Scorecard</strong> ग्रेड, श्रेणी, AI गहराई, अनुपालन संकेत, और SAML/MFA समर्थन।</p>

    <h3>Grip breach alerts <span class="new-badge">2.6.2 में नया</span></h3>
    <p>Grip प्लेटफ़ॉर्म में सुरक्षा घटनाएं भी फीड कर सकता है। कनेक्शन कार्ड पर <span class="field-label">Flow Grip breach information into Breach / Cyber Alerts</span> टिक करें और Grip &ldquo;Security Incident Detected&rdquo; अलर्ट प्रत्येक sync पर आपकी <a href="#breach-alerts">Breach / Cyber Alerts</a> सूची में लिखे जाते हैं। (इसके लिए <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email Settings</span> के अंतर्गत Breach/Cyber Alerts सुविधा को सक्षम करने की भी आवश्यकता है।)</p>
    <div class="callout callout-info">
        <strong>व्यक्तिगत डेटा विश्राम में एन्क्रिप्ट किया जाता है।</strong> कैश्ड Grip डेटा में नाम, ईमेल पते और अन्य व्यक्तिगत विवरण डेटाबेस में एन्क्रिप्ट किए जाते हैं और केवल तभी डिक्रिप्ट किए जाते हैं जब ऐप में दिखाए जाते हैं या API द्वारा लौटाए जाते हैं। यह स्वचालित है और इसके लिए कोई कॉन्फ़िगरेशन आवश्यक नहीं है।
    </div>
</div>

<div class="doc-section" id="shadow-saas-hero">
    <h2>Hero Shadow SaaS Integration <span class="new-badge">2.6.2 में नया</span></h2>
    <p><strong>HERO Security</strong> एक वैकल्पिक Shadow SaaS प्रदाता है। Grip के बजाय, आप एक HERO खाता कनेक्ट कर सकते हैं और प्लेटफ़ॉर्म HERO द्वारा खोजे गए विक्रेताओं को &mdash; उनकी स्थिति, एक जोखिम स्कोर, सबसे सक्रिय संपर्क और उपयोगकर्ता गणना के साथ &mdash; उसी <a href="#tprm-shadow-saas">Shadow SaaS</a> सूची में खींचता है। Grip और Hero <strong>परस्पर अनन्य</strong> हैं: Hero सक्षम करने से Grip स्वचालित रूप से अक्षम हो जाता है (और इसके विपरीत), इसलिए सूची हमेशा ठीक एक प्रदाता द्वारा फीड की जाती है।</p>
    <p>इसे एक प्रशासक द्वारा <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> पर <strong>Hero</strong> टैब पर कॉन्फ़िगर किया जाता है।</p>

    <h3>Hero कनेक्ट करना (चरण दर चरण)</h3>
    <ol class="steps">
        <li>HERO एडमिन पैनल में, एक <strong>API client</strong> बनाएं और उसकी <strong>Client ID</strong> और <strong>Client Secret</strong> कॉपी करें (secret केवल एक बार दिखाई देती है)।</li>
        <li>प्लेटफ़ॉर्म में, <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> पर जाएं और <strong>HERO Security Connection</strong> कार्ड खोजने के लिए <strong>Hero</strong> टैब खोलें।</li>
        <li><span class="field-label">Enable HERO Security integration</span> टिक करें (यह Grip को अक्षम करता है)।</li>
        <li>जब तक अन्यथा न कहा जाए <span class="field-label">Server (Base URL)</span> को <code>https://api.herosecurity.ai/stable</code> पर छोड़ दें, और अपनी <span class="field-label">Client ID</span> और <span class="field-label">Client Secret</span> पेस्ट करें।</li>
        <li><span class="btn-label">Save Configuration</span> पर क्लिक करें, फिर <span class="btn-label">Test Connection</span>। एक सफलता संदेश इस तरह दिखता है <em>"Connected to HERO — sample returned 1 record(s)"</em>।</li>
    </ol>

    <h3>बाद में आप क्या देखेंगे</h3>
    <p>HERO विक्रेता <span class="menu-label">Shadow SaaS</span> पृष्ठ पर Grip ऐप्स की तरह ही दिखाई देते हैं &mdash; <strong>Pending</strong> प्रविष्टियों के रूप में जिन्हें आप Allow, Deny या Dismiss कर सकते हैं। प्रत्येक विक्रेता के लिए प्लेटफ़ॉर्म रिकॉर्ड करता है:</p>
    <ul>
        <li><strong>Risk Score (1&ndash;5)</strong> &mdash; HERO के पास उस विक्रेता के लिए सबसे गंभीर खुली सुरक्षा समस्या से प्राप्त (critical&nbsp;=&nbsp;5 से low&nbsp;=&nbsp;2; बिना खुली समस्याओं वाले विक्रेताओं को बिना स्कोर के छोड़ा जाता है)। यह वही 1&ndash;5 पैमाना है जो Grip उपयोग करता है।</li>
        <li><strong>Relationship Manager</strong> &mdash; विक्रेता का सबसे सक्रिय देखा गया संपर्क (उच्चतम ईमेल गतिविधि वाला उपयोगकर्ता)।</li>
        <li><strong>Number of Users</strong> &mdash; कितने उपयोगकर्ताओं को विक्रेता के साथ इंटरैक्ट करते देखा गया।</li>
        <li><strong>Risk Type</strong> &mdash; HERO के engagement संकेतों (authorization, activity, commercial engagement) और खुली-समस्या गणना का सारांश।</li>
    </ul>
    <p>कुछ कॉलम जो अन्य स्रोत प्रदान करते हैं (एप्लिकेशन श्रेणी, MFA समर्थन, उल्लंघन इतिहास, ट्रैफ़िक वॉल्यूम, फ़ाइल-शेयरिंग) HERO API का हिस्सा नहीं हैं, इसलिए वे Hero पंक्तियों के लिए खाली रहते हैं।</p>

    <div class="callout callout-info">
        <strong>sync समय पर ध्यान दें।</strong> HERO प्रति विक्रेता अपना डेटा लौटाता है और अनुरोधों को rate-limit करता है, इसलिए एक बड़े tenant का पूर्ण ताज़ा पृष्ठभूमि में कई मिनट चलता है। शेड्यूल की गई जॉब और "Run Now" दोनों स्वचालित रूप से HERO की सीमाओं के भीतर रहने के लिए खुद को pace करते हैं।
    </div>
</div>

<div class="doc-section" id="zscaler">
    <h2>Zscaler Blocking Integration <span class="new-badge">2.6.2 में नया</span></h2>
    <p><strong>Zscaler</strong> एक वेब-सुरक्षा सेवा है जो वेबसाइटों तक पहुंच को ब्लॉक कर सकती है। इस एकीकरण के साथ, जब आप Shadow SaaS पृष्ठ पर एक अनुचित ऐप को <strong>Deny</strong> करते हैं, तो प्लेटफ़ॉर्म स्वचालित रूप से उस ऐप के वेब डोमेन को आपके Zscaler खाते में एक ब्लॉकिंग सूची में जोड़ सकता है &mdash; ताकि लोग अब इसे नहीं पहुंच सकते। बाद में <strong>Allow</strong> क्लिक करने से ब्लॉक हट जाता है।</p>
    <p>इसे एक प्रशासक द्वारा <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> पर <strong>Zscaler Connection</strong> कार्ड पर कॉन्फ़िगर किया जाता है।</p>

    <h3>Zscaler कनेक्ट करना (चरण दर चरण)</h3>
    <ol class="steps">
        <li>Zscaler (ZIdentity) में, एक <strong>API Client</strong> बनाएं और उसकी <strong>Client ID</strong> और <strong>Client Secret</strong> कॉपी करें। अपना <strong>vanity domain</strong> नोट करें (<code>.zslogin.net</code> से पहले का हिस्सा)।</li>
        <li>ZIA में, एक <strong>custom URL Category</strong> बनाएं (या चुनें) जिसमें ब्लॉक किए गए डोमेन जोड़े जाएंगे, और उसका सटीक नाम नोट करें।</li>
        <li>प्लेटफ़ॉर्म के <strong>Zscaler Connection</strong> कार्ड में, <span class="field-label">Enable Zscaler URL-Category blocking on Deny</span> टिक करें।</li>
        <li><span class="field-label">API URL</span> (डिफ़ॉल्ट <code>https://api.zsapi.net</code>), <span class="field-label">ZIdentity Vanity Domain</span>, <span class="field-label">Client ID</span>, <span class="field-label">Client Secret</span>, और <span class="field-label">URL Category</span> नाम भरें।</li>
        <li><span class="btn-label">Save Configuration</span> पर क्लिक करें, फिर credentials काम करते हैं यह पुष्टि करने के लिए <span class="btn-label">Test Connection</span> पर क्लिक करें।</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/zscaler-card.png" alt="Zscaler Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Zscaler Connection.</strong> सक्षम होने पर, Shadow SaaS ऐप पर <strong>Deny</strong> बटन उसके डोमेन को यहाँ आपके द्वारा नामित URL Category में जोड़ता है। Category Zscaler में पहले से मौजूद होनी चाहिए।</figcaption>
    </figure>
    <div class="callout callout-info">
        <strong>यदि ब्लॉकिंग बंद है,</strong> तो एक ऐप को deny करने से केवल प्लेटफ़ॉर्म में इसे अनुचित चिह्नित किया जाता है; Zscaler को कुछ नहीं भेजा जाता। आपको दिखाई देगा <em>"Marked unsanctioned. Zscaler integration is not enabled; domain not added to URL Category."</em>
    </div>
</div>


<div class="doc-section" id="breach-alerts">
    <h2>Breach / Cyber Alerts <span class="new-badge">2.6.2 में नया</span></h2>
    <p><strong>Breach / Cyber Alerts</strong> पृष्ठ आपके विक्रेता आपूर्ति श्रृंखला के लिए उल्लंघन और threat-intelligence संकेतों को एक स्थान पर एकत्र करता है। इसे साइडबार में <span class="menu-label">Breach / Cyber Alerts</span> &rarr; <span class="menu-label">Breach Alerts</span> के अंतर्गत खोलें; एक लाल बैज नए अलर्ट की संख्या दिखाता है।</p>

    <h3>अलर्ट कहाँ से आते हैं</h3>
    <p>अलर्ट में AI Breach &amp; OSINT स्कैनर द्वारा सामने लाए गए उल्लंघन (देखें <a href="#admin-ai">AI Integration</a>) और, सक्षम होने पर, <a href="#shadow-saas-grip">Grip</a> से सुरक्षा घटनाएं शामिल हैं। प्रत्येक अलर्ट प्रभावित इकाई, संभावित रूप से प्रभावित उपयोगकर्ता, तकनीक, और इसका पता कब चला, दिखाता है। जिस SaaS ऐप को आपने एक विक्रेता के रूप में ऑनबोर्ड <strong>नहीं</strong> किया है, उस पर एक घटना को संभावित रूप से प्रभावित उपयोगकर्ताओं की संख्या के साथ <strong>&ldquo;Shadow SaaS&rdquo;</strong> टैग किया जाता है; यदि वह ऐप बाद में ऑनबोर्ड किया जाता है, तो भविष्य की घटनाएं इसके बजाय विक्रेता से संलग्न होती हैं।</p>

    <h3>कौन प्रभावित हुआ</h3>
    <p>Grip-स्रोत वाली घटना के लिए, प्रभावित-उपयोगकर्ता गणना उस ऐप के लिए एक <strong>affected-users</strong> सूची से लिंक करती है। सूची पेज की गई और फ़िल्टर करने योग्य है (उदाहरण के लिए प्रमाणीकरण विधि द्वारा), और किसी विशिष्ट व्यक्ति को खोजने के लिए एक <strong>Search by name or email</strong> बॉक्स है। चूंकि roster एन्क्रिप्ट किया हुआ संग्रहीत है, खोज एप्लिकेशन में डिक्रिप्ट किए गए डेटा पर चलती है, इसलिए यह sorting और paging की तरह ही काम करती है।</p>

    <h3>अलर्ट को बल्क में निपटाना</h3>
    <p>प्रशासकों और Cyber TPRM उपयोगकर्ताओं को सूची पर एक multi-select टूलबार मिलता है। आप जो अलर्ट चाहते हैं उन्हें टिक करें (या <strong>Check all</strong> का उपयोग करें) और उन सभी पर एक बार में एक क्रिया लागू करें:</p>
    <ul>
        <li><span class="btn-label">Acknowledge</span> &mdash; अलर्ट को देखे गए के रूप में चिह्नित करें।</li>
        <li><span class="btn-label">False Positive</span> &mdash; उन्हें एक वास्तविक समस्या नहीं के रूप में चिह्नित करें।</li>
        <li><span class="btn-label">Delete</span> &mdash; उन्हें हटाएं। <strong>केवल प्रशासक</strong>, और चलने से पहले पुष्टि की जाती है।</li>
    </ul>
</div>


<!-- ================================================================
     ADMIN PORTAL
     ================================================================ -->
<div class="doc-section" id="admin-general">
    <h2>Admin Portal: सामान्य सेटिंग</h2>
    <p>Admin Portal साइडबार में <span class="menu-label">Administration</span> के माध्यम से (केवल एडमिन उपयोगकर्ता) या शीर्ष बार में <span class="btn-label">Admin</span> लिंक के माध्यम से सुलभ है।</p>
    <p>General Settings में शामिल हैं: एप्लिकेशन नाम, कंपनी नाम, सपोर्ट ईमेल और सिस्टम-व्यापी कॉन्फ़िगरेशन विकल्प।</p>
</div>

<div class="doc-section" id="admin-branding">
    <h2>Branding &amp; Theme</h2>
    <p>प्लेटफ़ॉर्म की उपस्थिति अनुकूलित करें: अपनी कंपनी का लोगो अपलोड करें, साइडबार रंग, हेडर रंग, बटन रंग और नेविगेशन चौड़ाई सेट करें। <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Branding</span> पर जाएं।</p>
</div>

<div class="doc-section" id="admin-users">
    <h2>User Management</h2>
    <p>उपयोगकर्ता खाते और समूह असाइनमेंट प्रबंधित करें। <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span> पर जाएं।</p>

    <h3>उपयोगकर्ताओं को ACL Groups में असाइन करना</h3>
    <ol class="steps">
        <li><span class="menu-label">Admin</span> &rarr; <span class="menu-label">Users</span> पर जाएं।</li>
        <li>सूची में उपयोगकर्ता खोजें।</li>
        <li>उपयोगकर्ता के नाम के बगल में <span class="btn-label">Groups</span> बटन पर क्लिक करें।</li>
        <li>चेकबॉक्स के साथ सभी उपलब्ध समूह दिखाने वाला एक मोडल दिखाई देगा। वे समूह चेक करें जो आप असाइन करना चाहते हैं (जैसे, <strong>Cyber GRC</strong>, <strong>Administrator</strong>)।</li>
        <li><span class="btn-label">Save Changes</span> पर क्लिक करें।</li>
    </ol>
    <p>शिप किए गए समूहों को असाइन करने से परे, super administrators अपने स्वयं के समूह एक कस्टम अनुमति सेट के साथ बना सकते हैं &mdash; देखें <a href="#admin-acl-groups">ACL Groups &amp; Custom Access Control</a>।</p>
</div>

<div class="doc-section" id="admin-acl-groups">
    <h2>ACL Groups &amp; Custom Access Control <span class="new-badge">2.6.2 में नया</span></h2>
    <p>प्लेटफ़ॉर्म सात अंतर्निहित समूहों के साथ शिप होता है (administrator, cyber_tprm, procurement, stakeholder, auditor, cyber_grc, grc_contributors)। v2.6.2 में, <strong>super administrators</strong> अपने स्वयं के समूह भी बना सकते हैं और ठीक-ठीक ट्यून कर सकते हैं कि प्रत्येक क्या कर सकता है। <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Access Control</span> &rarr; <span class="menu-label">ACL Groups</span> खोलें। कोई भी प्रशासक इस पृष्ठ को देख सकता है; केवल super administrators create, edit, और permission नियंत्रण देखते हैं।</p>

    <h3>शिप किए गए समूह सुरक्षित हैं</h3>
    <p>सात अंतर्निहित समूह <strong>System</strong> चिह्नित हैं। उन्हें हटाया या नाम बदला नहीं जा सकता, और उनकी अनुमतियाँ केवल-पढ़ने योग्य हैं &mdash; आप <span class="btn-label">View Permissions</span> खोलकर देख सकते हैं कि वे वास्तव में क्या प्रदान करते हैं, लेकिन उन्हें बदल नहीं सकते। यह उन डिफ़ॉल्ट को स्थिर रखता है जिन पर सभी निर्भर करते हैं।</p>

    <h3>एक कस्टम समूह बनाना</h3>
    <ol class="steps">
        <li><span class="btn-label">+ Create Group</span> पर क्लिक करें।</li>
        <li>एक <span class="field-label">Group Name (machine)</span> (छोटे अक्षर, संख्याएं, अंडरस्कोर &mdash; एक बार बनने के बाद यह तय हो जाता है), एक मित्रवत <span class="field-label">Display Name</span>, और एक <span class="field-label">Description</span> दर्ज करें।</li>
        <li>वैकल्पिक रूप से अपने शुरुआती बिंदु के रूप में एक मौजूदा समूह (एक System समूह सहित) को <strong>क्लोन</strong> करने के लिए <span class="field-label">Copy permissions from</span> का उपयोग करें &mdash; फिर इसे परिष्कृत करें। शून्य से बनाने के लिए इसे <em>&mdash; Start with no permissions &mdash;</em> पर छोड़ दें।</li>
        <li><span class="btn-label">Create Group</span> पर क्लिक करें।</li>
    </ol>

    <h3>अनुमति मैट्रिक्स ट्यून करना</h3>
    <p>एक कस्टम समूह का <span class="btn-label">Permissions</span> खोलें। अनुमतियाँ मॉड्यूल द्वारा समूहीकृत होती हैं (Vendor Onboarding, FAIR Analysis, Assessments, Security Rating (SRS), Annual Reviews, GRC, और Other)। प्रत्येक अनुमति को या तो <strong>Read</strong> या <strong>Read/Write</strong> के रूप में टैग किया जाता है, और प्रत्येक मॉड्यूल में तीन एक-क्लिक प्रीसेट होते हैं:</p>
    <ul>
        <li><span class="btn-label">Read</span> &mdash; उस मॉड्यूल के लिए केवल view/list/export अनुमतियाँ प्रदान करता है।</li>
        <li><span class="btn-label">Read &amp; Write</span> &mdash; सब कुछ प्रदान करता है (view <em>और</em> change)।</li>
        <li><span class="btn-label">None</span> &mdash; मॉड्यूल को साफ़ करता है।</li>
    </ul>
    <p>समाप्त होने पर <span class="btn-label">Save Permissions</span> पर क्लिक करें। <strong>Read</strong> प्रदान करना कभी write पहुंच का संकेत नहीं देता &mdash; कुछ बदलने की क्षमता हमेशा एक अलग, स्पष्ट grant होती है। सभी समूह परिवर्तन ऑडिट-लॉग किए जाते हैं।</p>
</div>

<div class="doc-section" id="admin-templates">
    <h2>Assessment Template Builder <span class="new-badge">2.6.2 में नया</span></h2>
    <p><strong>Template Builder</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Template Builder</span>) वह जगह है जहाँ प्रशासक और Cyber TPRM उपयोगकर्ता मूल्यांकन और ऑनबोर्डिंग प्रश्नावलियाँ डिज़ाइन करते हैं। एक टेम्पलेट बनाने के लिए <span class="btn-label">+ Add Section</span> और <span class="btn-label">+ Add Question</span> का उपयोग करें। कुछ v2.6.2 अतिरिक्त उल्लेखनीय हैं।</p>

    <h3>प्रश्न प्रकार और फ़ील्ड मैपिंग</h3>
    <p>एक प्रश्न के <span class="field-label">Question Type</span> में अब परिचित text, dropdown, और radio प्रकारों के अलावा <strong>Phone</strong>, <strong>VAT Number</strong>, <strong>Checkboxes</strong>, और <strong>Button Group (Multi)</strong> शामिल हैं (प्रत्येक क्या कैप्चर करता है इसके लिए देखें <a href="#custom-onboarding">कस्टम ऑनबोर्डिंग फ़ील्ड</a>)। एक प्रश्न का <span class="field-label">Field Name</span> उसके उत्तर को एक विक्रेता फ़ील्ड पर मैप करता है; एक अंतर्निहित फ़ील्ड या एक कस्टम फ़ील्ड चुनें जिसे आपने <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Field Reference</span> के अंतर्गत पंजीकृत किया है।</p>

    <h3>Certificate Upload Instructions</h3>
    <p>एक टेम्पलेट पर आप <span class="field-label">Certificate Upload Instructions</span> भर सकते हैं &mdash; वह पाठ जो एक विक्रेता को <em>&ldquo;Do you have a Certificate?&rdquo;</em> संकेत में दिखाया जाता है। यह एक टेम्पलेट को केवल ISO 27001 ही नहीं, बल्कि किसी भी प्रमाणपत्र (SOC 2 Type 2, ISO 27001, इत्यादि) को आमंत्रित करने देता है। यदि आप इसे खाली छोड़ देते हैं, तो एक सामान्य संदेश दिखाया जाता है।</p>

    <h3>ऑनबोर्डिंग टेम्पलेट पर भूमिका-आधारित दृश्यता</h3>
    <p><strong>ऑनबोर्डिंग</strong> टेम्पलेट के लिए, कस्टम सेक्शन और प्रश्न <span class="field-label">Visible to Roles</span> और <span class="field-label">Visible and Editable Roles</span> नियंत्रण रखते हैं, इसलिए आप तय करते हैं कि प्रत्येक कस्टम फ़ील्ड को कौन देख और संपादित कर सकता है। देखें <a href="#custom-onboarding">कस्टम ऑनबोर्डिंग फ़ील्ड &amp; Custom Data टैब</a>।</p>

    <h3>निष्क्रिय टेम्पलेट डिफ़ॉल्ट रूप से छुपे होते हैं</h3>
    <p>टेम्पलेट सूची केवल <strong>सक्रिय</strong> टेम्पलेट दिखाती है। यदि किसी को निष्क्रिय किया गया है, तो एक <span class="btn-label">Show Deactivated (N)</span> बटन उन्हें प्रकट करता है (और <span class="btn-label">Hide Deactivated (N)</span> पर वापस टॉगल करता है), जो एक लंबे समय तक चलने वाले tenant की सूची को वास्तव में उपयोग में आने वाले टेम्पलेट पर केंद्रित रखता है बिना सेवानिवृत्त टेम्पलेट तक पहुंच खोए।</p>
</div>

<div class="doc-section" id="admin-email">
    <h2>Email Configuration</h2>
    <p>ईमेल अधिसूचनाएं भेजने के लिए SMTP सेटिंग कॉन्फ़िगर करें। <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Email</span> पर जाएं। सेटिंग में SMTP होस्ट, पोर्ट, उपयोगकर्ता नाम, पासवर्ड, एन्क्रिप्शन विधि (TLS/SSL), और प्रेषक पता शामिल हैं।</p>
</div>

<div class="doc-section" id="admin-saml">
    <h2>SAML / SSO</h2>
    <p>SAML 2.0 का उपयोग करके Single Sign-On कॉन्फ़िगर करें। <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span> पर जाएं। यह उपयोगकर्ताओं को आपके संगठन के identity provider (Okta, Azure AD, आदि) का उपयोग करके लॉग इन करने की अनुमति देता है।</p>
    <ol class="steps">
        <li><span class="field-label">Enable SAML 2.0</span> टिक करें।</li>
        <li>सभी आवश्यक Identity Provider फ़ील्ड (<span class="field-label">IdP Entity ID</span>, <span class="field-label">IdP Single Sign-On URL</span>, <span class="field-label">IdP X.509 Certificate</span>) और Service Provider फ़ील्ड (<span class="field-label">SP Entity ID</span>, <span class="field-label">SP ACS URL</span>) भरें। SSO केवल तभी सक्रिय होता है जब इन <strong>सभी</strong> को भरा जाता है &mdash; आंशिक रूप से पूर्ण फ़ॉर्म अक्षम रहता है।</li>
        <li><span class="btn-label">Save</span> पर क्लिक करें।</li>
    </ol>

    <h3>स्थानीय लॉगिन और SSO एक साथ चलाना</h3>
    <p>डिफ़ॉल्ट रूप से, SSO सक्षम करने से स्थानीय username/password फ़ॉर्म <strong>बंद नहीं</strong> होता &mdash; लॉगिन पृष्ठ एक <strong>Sign in with SSO</strong> बटन <em>और</em> एक स्थानीय साइन-इन विकल्प दोनों दिखाता है, इसलिए दोनों एक साथ काम करते हैं। यह व्यवहार एक एकल स्थानीय-लॉगिन स्विच द्वारा नियंत्रित होता है:</p>
    <table>
        <tr><th>मोड</th><th>उपयोगकर्ता क्या देखते हैं</th></tr>
        <tr><td><strong>Local login enabled</strong> (डिफ़ॉल्ट)</td><td>SSO बटन <em>और</em> username/password फ़ॉर्म। दोनों एक साथ चलाने के लिए इसका उपयोग करें।</td></tr>
        <tr><td><strong>Local login disabled</strong> (केवल-SSO)</td><td>SSO सामान्य उपयोगकर्ताओं के लिए एकमात्र रास्ता है। नामित <strong>break-glass admin</strong> खाता अभी भी स्थानीय रूप से साइन इन कर सकता है, इसलिए एक टूटा हुआ identity provider कभी भी सभी को लॉक नहीं कर सकता।</td></tr>
    </table>
    <p>यदि SAML वास्तव में कॉन्फ़िगर नहीं किया गया है, तो स्विच को अनदेखा किया जाता है और स्थानीय लॉगिन हमेशा उपलब्ध रहता है (anti-lockout सुरक्षा जाल)।</p>

    <h3>Break-glass: SAML और स्थानीय लॉगिन एक साथ अनुमति दें (config फ़ाइल) <span class="new-badge">2.6.2 में नया</span></h3>
    <p>स्थानीय-लॉगिन स्विच दो तरीकों से सेट किया जा सकता है। config-file सेटिंग, जब मौजूद हो, <strong>डेटाबेस मान पर प्राथमिकता लेती है</strong> &mdash; एक break-glass नियंत्रण जिसे डेटाबेस पहुंच की आवश्यकता नहीं है, इसलिए आप हमेशा स्थानीय लॉगिन पुनर्स्थापित कर सकते हैं भले ही SSO खराब हो।</p>
    <table>
        <tr><th>कहाँ</th><th>कैसे</th></tr>
        <tr><td>Admin &rarr; SAML पृष्ठ</td><td><strong>Connection Settings</strong> कार्ड में, <span class="field-label">Allow local username/password login (in addition to SSO)</span> टिक या अनटिक करें और <span class="btn-label">Save SAML Configuration</span> पर क्लिक करें। यह <code>local_login_enabled</code> सेटिंग लिखता है (डिफ़ॉल्ट रूप से सक्षम) &mdash; कोई SQL आवश्यक नहीं।</td></tr>
        <tr><td>Config फ़ाइल (सेट होने पर जीतती है)</td><td><code>config/config.php</code> में, <code>auth</code> ब्लॉक के अंतर्गत, स्थानीय लॉगिन हमेशा उपलब्ध रखने के लिए <code>'local_login_enabled' =&gt; true</code> सेट करें (दोनों local + SSO), या केवल-SSO के लिए <code>false</code>। यह ऊपर के टॉगल को <strong>ओवरराइड</strong> करता है; जब यह सेट होता है, SAML पृष्ठ पर चेकबॉक्स केवल-पढ़ने योग्य दिखाया जाता है। इसे UI से फिर से प्रबंधित करने के लिए लाइन हटाएं। <code>config.php</code> संपादित करने के बाद कंटेनर पुनरारंभ करें।</td></tr>
    </table>
    <p>किसी डेटाबेस परिवर्तन के बिना <strong>दोनों SAML और स्थानीय लॉगिन</strong> चलाने के लिए, ऊपर के अनुसार SAML कॉन्फ़िगर करें और <code>config/config.php</code> के <code>auth</code> ब्लॉक में यह जोड़ें, फिर कंटेनर पुनरारंभ करें:</p>
    <pre><code>'auth' =&gt; [
    // ...
    // Break-glass: true = local login always available alongside SSO;
    // false = SSO-only (break-glass admin can still log in locally).
    'local_login_enabled' =&gt; true,
],</code></pre>
</div>

<div class="doc-section" id="admin-ai">
    <h2>AI Integration</h2>
    <p>मूल्यांकन नोट परिष्कृत करना, नियंत्रण सुझाव, विक्रेता टिप्पणी, AI-सहायक FAIR जोखिम विश्लेषण और रिपोर्ट भाषा सहायता सहित AI-संचालित सुविधाएं सक्षम करें। एक प्रदाता चुनने और उसकी API कुंजी दर्ज करने के लिए <span class="menu-label">Admin</span> &rarr; <span class="menu-label">AI Platform</span> पर जाएं। एक समय में केवल एक प्लेटफ़ॉर्म सक्रिय होता है।</p>
    <p>समर्थित AI प्लेटफ़ॉर्म:</p>
    <ul>
        <li><strong>Anthropic (Claude)</strong> <span class="new-badge">2.6.2 में नया</span> &mdash; Claude के native API से सीधे जुड़ता है (जैसे <code>claude-opus-4-8</code>)। अपनी Anthropic API कुंजी पेस्ट करें; endpoint डिफ़ॉल्ट रूप से मानक Messages URL पर होता है। लाइव <strong>web search</strong> का समर्थन करता है, इसलिए Breach Alerts और OSINT स्कैन वर्तमान, उद्धृत स्रोतों पर आधारित होते हैं।</li>
        <li><strong>OpenAI (ChatGPT)</strong> <span class="new-badge">2.6.2 में नया</span> &mdash; OpenAI से सीधे जुड़ता है (जैसे <code>gpt-4o</code>)। अपनी OpenAI API कुंजी पेस्ट करें। Breach &amp; OSINT स्कैन के लिए यह एक वेब-खोज-सक्षम मॉडल (डिफ़ॉल्ट <code>gpt-4o-search-preview</code>) का उपयोग करता है ताकि वे स्कैन लाइव स्रोतों पर आधारित हों।</li>
        <li><strong>OpenWebUI</strong> &mdash; एक OpenAI-संगत endpoint के विरुद्ध JWT bearer token।</li>
        <li><strong>LibreChat</strong> &mdash; API-key auth, agent-based; agent अपना मॉडल और sampling प्रबंधित करता है।</li>
        <li><strong>Custom</strong> &mdash; किसी अन्य OpenAI-संगत (या orchestrator) endpoint के लिए curl-style headers + body template पेस्ट करें।</li>
    </ul>
    <p><strong>मॉडल चुनना &amp; लोड करना:</strong> एक कुंजी दर्ज करने और <strong>सहेजने</strong> के बाद, उस प्लेटफ़ॉर्म के कार्ड पर <span class="btn-label">Load Models</span> पर क्लिक करें उपलब्ध मॉडल सूची लाने के लिए (OpenWebUI / LibreChat / OpenAI)। Anthropic के लिए, मॉडल नाम सीधे टाइप करें (जैसे <code>claude-opus-4-8</code>)।</p>
    <p><strong>Breach Alerts grounding:</strong> Breach &amp; OSINT स्कैनर को एक ऐसे प्रदाता की आवश्यकता है जो वेब खोज सके। <strong>Anthropic (Claude)</strong> और <strong>OpenAI (ChatGPT)</strong> दोनों मूल रूप से ground करते हैं; OpenWebUI / LibreChat तभी ground करते हैं जब अंतर्निहित agent में ब्राउज़िंग हो; Custom प्लेटफ़ॉर्म केवल तभी ground करता है जब Web Search URL कॉन्फ़िगर किया गया हो।</p>
    <p>सक्रिय AI प्रदाता <strong>मूल्यांकन प्रश्नों के स्वचालित अनुवाद</strong> को भी संचालित करता है (देखें <a href="#language">अपनी भाषा बदलना</a>)।</p>
</div>

<div class="doc-section" id="admin-updates">
    <h2>प्लेटफ़ॉर्म अपडेट करना <span class="new-badge">2.6.2 में नया</span></h2>
    <p>प्रशासक प्लेटफ़ॉर्म के अंदर से नए संस्करणों की जांच कर सकते हैं और लागू कर सकते हैं। <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Version</span> पर जाएं।</p>
    <ol class="steps">
        <li><strong>Current Status</strong> कार्ड आपका <span class="field-label">Installed Version</span> और क्या कोई नया उपलब्ध है दिखाता है।</li>
        <li>पुष्टि करें कि <span class="field-label">Registry Hostname</span> सही है (आपकी image रजिस्ट्री), फिर <span class="btn-label">Check for Updates</span> पर क्लिक करें।</li>
        <li>यदि एक नया संस्करण सूचीबद्ध है, तो इसे लागू करने के लिए ऑन-स्क्रीन <strong>Upgrade</strong> क्रिया का पालन करें।</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-version.png?v=20260711" alt="Version Management page showing installed version 2.6.2" loading="lazy">
        <figcaption><strong>Admin &rarr; Version.</strong> यहाँ इंस्टॉल किया गया संस्करण <strong>v2.6.2</strong> है और प्लेटफ़ॉर्म रिपोर्ट करता है कि यह अद्यतित है। यह वह जगह भी है जहाँ आप पुष्टि करते हैं कि यह मार्गदर्शिका किस संस्करण पर लागू होती है।</figcaption>
    </figure>
</div>


<!-- ================================================================
     FAQ (SEARCHABLE)
     ================================================================ -->
<div class="doc-section" id="faq">
    <h2>अक्सर पूछे जाने वाले प्रश्न</h2>
    <p>प्रश्नों को तुरंत फ़िल्टर करने के लिए नीचे एक कीवर्ड टाइप करें &mdash; उदाहरण के लिए <em>भाषा</em>, <em>VID</em>, <em>onboard</em>, <em>Grip</em>, या <em>पासवर्ड</em>।</p>
    <div class="faq-search-wrap">
        <input type="text" id="faqSearch" class="faq-search" placeholder="Search the FAQ&hellip;" aria-label="Search frequently asked questions" autocomplete="off">
    </div>
    <p class="faq-count" id="faqCount"></p>
    <div id="faqList">

        <details class="faq-item"><summary>क्या उपयोगकर्ता SSO और स्थानीय पासवर्ड दोनों से एक साथ साइन इन कर सकते हैं?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>हाँ। SAML/SSO सक्षम करने से डिफ़ॉल्ट रूप से स्थानीय लॉगिन बंद <strong>नहीं</strong> होता &mdash; लॉगिन पृष्ठ एक <strong>Sign in with SSO</strong> बटन और एक स्थानीय username/password विकल्प एक साथ दिखाता है। आप इसे <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span> पृष्ठ पर <strong>Allow local username/password login</strong> चेकबॉक्स से नियंत्रित करते हैं (दोनों चलाने के लिए टिक रखें; केवल-SSO के लिए अनटिक करें, जहाँ break-glass admin अभी भी स्थानीय रूप से लॉग इन कर सकता है)। बिना-डेटाबेस break-glass नियंत्रण के लिए, वही सेटिंग <code>config/config.php</code> में <code>'local_login_enabled' =&gt; true</code> के माध्यम से बाध्य की जा सकती है, जो चेकबॉक्स को ओवरराइड करती है। देखें <a href="#admin-saml">SAML / SSO</a>।</p></div></details>

        <details class="faq-item"><summary>SSO गलत तरीके से कॉन्फ़िगर है और कोई लॉग इन नहीं कर सकता। मैं वापस कैसे पहुंचूं?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>break-glass नियंत्रण का उपयोग करें: <code>config/config.php</code> में, <code>auth</code> ब्लॉक के अंतर्गत, <code>'local_login_enabled' =&gt; true</code> सेट करें और कंटेनर पुनरारंभ करें। यह डेटाबेस सेटिंग की परवाह किए बिना स्थानीय username/password फ़ॉर्म पुनः सक्षम करता है, ताकि आप साइन इन कर सकें और SAML कॉन्फ़िगरेशन ठीक कर सकें। देखें <a href="#admin-saml">SAML / SSO</a>।</p></div></details>

        <details class="faq-item"><summary>मैं प्लेटफ़ॉर्म की भाषा कैसे बदलूं?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p><strong>Profile</strong> (ऊपरी-दाएं) पर क्लिक करें, <strong>Language Preference</strong> कार्ड खोलें, अपनी भाषा चुनें और <strong>Update Language</strong> पर क्लिक करें। यह केवल आपकी अपनी स्क्रीन बदलता है। देखें <a href="#language">अपनी भाषा बदलना</a>।</p></div></details>

        <details class="faq-item"><summary>कौन सी भाषाएं समर्थित हैं?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>English, Spanish, Italian, Ukrainian, Chinese (Simplified), Hindi, French और Portuguese। आपका प्रशासक तय करता है कि इनमें से कौन सी आपकी सूची में दिखाई देती हैं; English हमेशा उपलब्ध है।</p></div></details>

        <details class="faq-item"><summary>मैंने अपनी भाषा बदली लेकिन कुछ टेक्स्ट अभी भी English में है। क्यों?<span class="faq-tag">Language</span></summary>
            <div class="faq-body"><p>भाषा बदलने के बाद भी कुछ अलग-अलग चीज़ें English में रह सकती हैं:</p>
            <ul>
                <li><strong>इंटरफ़ेस टेक्स्ट जिसका अभी तक अनुवाद नहीं हुआ है।</strong> मेनू, बटन और लेबल वहाँ अनुवादित होते हैं जहाँ आपकी भाषा के लिए अनुवाद मौजूद है। यदि किसी विशेष स्ट्रिंग का अभी तक आपकी भाषा में अनुवाद नहीं हुआ है, तो वह रिक्त दिखाने के बजाय English पर वापस चली जाती है &mdash; इसलिए आपको कभी-कभी कोई English लेबल दिख सकता है।</li>
                <li><strong>जो कुछ भी टाइप किया गया था।</strong> जो सामग्री आप या आपके विक्रेता दर्ज करते हैं &mdash; विक्रेता नाम, नोट, अपलोड किए गए दस्तावेज़ नाम, मुक्त-पाठ उत्तर &mdash; वह ठीक वैसे ही दिखाई जाती है जैसे यह लिखी गई थी, जिस भी भाषा में थी।</li>
                <li><strong>AI प्रदाता के बिना मूल्यांकन प्रश्न।</strong> विक्रेता मूल्यांकन <em>प्रश्न</em> पाठ स्वचालित रूप से केवल तभी अनुवादित होता है जब आपके प्रशासक ने एक AI प्रदाता कॉन्फ़िगर किया हो; उसके बिना, प्रश्न उसी भाषा में रहते हैं जिसमें वे लिखे गए थे। संग्रहीत उत्तर मान हमेशा English में रहते हैं ताकि स्कोरिंग सुसंगत रहे।</li>
                <li><strong>ईमेल और कुछ तृतीय-पक्ष घटक</strong> आपकी भाषा सेटिंग द्वारा नियंत्रित नहीं होते।</li>
            </ul>
            <p>यदि आप कोई इंटरफ़ेस लेबल देखते हैं जिसका अनुवाद होना चाहिए लेकिन नहीं है, तो अपने प्रशासक को बताएं ताकि गायब टेक्स्ट जोड़ा जा सके।</p></div></details>

        <details class="faq-item"><summary>मैं अपने विक्रेता को समीक्षा के लिए सबमिट क्यों नहीं कर सकता?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>एक विक्रेता केवल तभी सबमिट किया जा सकता है जब उसने <strong>Procurement Onboarding</strong> (<strong>Yes</strong> पर सेट) पूरी कर ली हो और 4&ndash;8 अंकों का एक वैध <strong>Vendor ID (VID)</strong> हो। विक्रेता खोलें, <strong>Vendor Information</strong> कार्ड पर दोनों भरें, सहेजें, फिर <strong>Submit for Review</strong> पर क्लिक करें। देखें <a href="#onboarding-workflow">Vendor Onboarding</a> और <a href="#troubleshooting">Troubleshooting</a>।</p></div></details>

        <details class="faq-item"><summary>Vendor ID (VID) क्या है और मुझे यह कहाँ से मिलेगा?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>VID एक 4&ndash;8 अंकीय संख्या है जो विक्रेता के ऑनबोर्ड होने पर आपकी खरीद प्रणाली द्वारा विक्रेता को असाइन की जाती है। यह यहाँ विक्रेता को आपके खरीद और वित्त रिकॉर्ड से जोड़ता है। यदि आपके पास एक नहीं है, तो विक्रेता ने अभी तक खरीद ऑनबोर्डिंग पूरी नहीं की है।</p></div></details>

        <details class="faq-item"><summary>मेरे विक्रेता पर फ़ील्ड "VSU Onboarded" कहती है, लेकिन मार्गदर्शिका "Procurement Onboarding" कहती है। कौन सी सही है?<span class="faq-tag">Onboarding</span></summary>
            <div class="faq-body"><p>वे एक ही फ़ील्ड हैं। इसे v2.6.2 में स्पष्ट <strong>"Procurement Onboarding"</strong> नाम दिया गया था। यदि आपकी स्क्रीन अभी भी <strong>"VSU Onboarded"</strong> दिखाती है, तो आपका इंस्टेंस अभी तक नवीनतम v2.6.2 image में अपग्रेड नहीं हुआ है &mdash; व्यवहार समान है।</p></div></details>

        <details class="faq-item"><summary>एक विक्रेता के लिए "AI Review" का क्या अर्थ है?<span class="faq-tag">AI Review</span></summary>
            <div class="faq-body"><p>यह उन विक्रेताओं के लिए एक अलग समीक्षा स्थिति है जिनकी सेवाएं AI का उपयोग करती हैं, ताकि उन्हें सामान्य समीक्षाओं से अलग ट्रैक किया जा सके। एक Cyber TPRM उपयोगकर्ता या एडमिन <strong>Force AI Review</strong> लिंक के साथ एक विक्रेता को इसमें ले जाता है। देखें <a href="#ai-review">विक्रेताओं के लिए AI Review</a>।</p></div></details>

        <details class="faq-item"><summary>मुझे "Force AI Review" लिंक नहीं दिख रहा। क्यों?<span class="faq-tag">AI Review</span></summary>
            <div class="faq-body"><p>यह केवल तभी दिखाई देता है जब आप अनुमोदन अनुमति के साथ विक्रेता संपादित कर रहे हों, विक्रेता का <strong>Services Use AI</strong> फ़ील्ड <strong>Yes</strong> हो, और विक्रेता पहले से AI Review में न हो।</p></div></details>

        <details class="faq-item"><summary>Procurement Cyber Status पृष्ठ क्या है?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>एक सरल-भाषा पृष्ठ (<strong>TPRM &rarr; Procurement &rarr; Cyber Status</strong>) जहाँ खरीद देख सकती है कि साइबर टीम किन विक्रेताओं की समीक्षा कर रही है और साइबर टीम द्वारा पोस्ट किए गए दिनांकित अपडेट पढ़ सकती है। देखें <a href="#procurement-cyber-status">Procurement Cyber Status</a>।</p></div></details>

        <details class="faq-item"><summary>खरीद को अपडेट ईमेल कैसे मिलते हैं?<span class="faq-tag">Procurement</span></summary>
            <div class="faq-body"><p>एक प्रशासक <strong>Admin &rarr; Email Settings</strong> के अंतर्गत <strong>Procurement Update Digest</strong> चालू करता है और प्राप्तकर्ता पते जोड़ता है। यह साप्ताहिक ईमेल किया जाता है (डिफ़ॉल्ट रूप से सोमवार सुबह 7:00 बजे) और मांग पर भी भेजा जा सकता है।</p></div></details>

        <details class="faq-item"><summary>Grip क्या है और यह यहाँ क्या करता है?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>Grip Security आपके संगठन में उपयोग किए जाने वाले SaaS ऐप्स खोजता है। जब कनेक्ट किया जाता है (<strong>Admin &rarr; Shadow SaaS</strong>), तो प्लेटफ़ॉर्म स्वचालित रूप से उन ऐप्स, उनकी उपयोगकर्ता गणनाएं, जोखिम स्कोर और अलर्ट आपकी Shadow SaaS सूची में खींचता है। देखें <a href="#shadow-saas-grip">Grip Shadow SaaS Integration</a>।</p></div></details>

        <details class="faq-item"><summary>Grip और Hero एकीकरण के बीच क्या अंतर है?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>दोनों एक तृतीय-पक्ष खोज सेवा &mdash; Grip Security या HERO Security &mdash; से एक ही Shadow SaaS सूची फीड करते हैं &mdash; और दोनों Zscaler ब्लॉकिंग और Scheduled Rehydration जॉब साझा करते हैं। वे <strong>परस्पर अनन्य</strong> हैं: एक सक्षम करने से दूसरा अक्षम हो जाता है, इसलिए आप जो भी प्रदाता आपका संगठन उपयोग करता है वह चलाते हैं। देखें <a href="#shadow-saas-hero">Hero Shadow SaaS Integration</a>।</p></div></details>

        <details class="faq-item"><summary>मेरा Grip "Test Connection" विफल रहा। मुझे क्या जांचना चाहिए?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>पुष्टि करें कि <strong>Server (Tenant Base URL)</strong> <code>/public/saas</code> में समाप्त होता है, कि <strong>API Token</strong> वर्तमान है, और कि आपका सर्वर Grip endpoint तक पहुंच सकता है। एक token त्रुटि <em>"Unauthorized — token rejected"</em> रिपोर्ट करती है; एक URL त्रुटि <em>"Endpoint not found — check base URL"</em> रिपोर्ट करती है।</p></div></details>

        <details class="faq-item"><summary>Zscaler एकीकरण क्या करता है?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p>जब आप एक अनुचित ऐप को <strong>Deny</strong> करते हैं, तो प्लेटफ़ॉर्म उसके वेब डोमेन को आपके Zscaler खाते में एक ब्लॉकिंग URL Category में जोड़ सकता है ताकि लोग इसे नहीं पहुंच सकें। बाद में <strong>Allow</strong> क्लिक करने से ब्लॉक हट जाता है। देखें <a href="#zscaler">Zscaler Blocking Integration</a>।</p></div></details>

        <details class="faq-item"><summary>Shadow SaaS ऐप पर Allow, Deny और Dismiss के बीच क्या अंतर है?<span class="faq-tag">Integrations</span></summary>
            <div class="faq-body"><p><strong>Allow</strong> ऐप को एक विक्रेता के रूप में ऑनबोर्ड करना शुरू करता है; <strong>Deny</strong> इसे अनुचित चिह्नित करता है (और Zscaler में इसे ब्लॉक कर सकता है); <strong>Dismiss</strong> इसे सूची से छुपाता है। Dismissed ऐप्स भविष्य के sync के बाद भी dismissed रहते हैं।</p></div></details>

        <details class="faq-item"><summary>GRC मॉड्यूल कौन देख सकता है?<span class="faq-tag">Access</span></summary>
            <div class="faq-body"><p><strong>Administrator</strong>, <strong>Cyber GRC</strong>, या <strong>Auditor</strong> समूहों के उपयोगकर्ता। यदि आप इसे नहीं देखते हैं, तो अपने प्रशासक से इनमें से किसी एक समूह में आपको जोड़ने के लिए कहें। देखें <a href="#roles">उपयोगकर्ता भूमिकाएं &amp; अनुमतियाँ</a>।</p></div></details>

        <details class="faq-item"><summary>मैं दो-कारक प्रमाणीकरण (2FA) कैसे चालू करूं?<span class="faq-tag">Account</span></summary>
            <div class="faq-body"><p><strong>Profile</strong> खोलें और इसे Google Authenticator या Microsoft Authenticator जैसे authenticator ऐप के साथ सक्षम करने के लिए <strong>Two-Factor Authentication (TOTP)</strong> कार्ड का उपयोग करें।</p></div></details>

        <details class="faq-item"><summary>क्या मैं इस दस्तावेज़ीकरण को सहेज या प्रिंट कर सकता हूं?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>हाँ। इस पृष्ठ के शीर्ष पर <strong>Download PDF</strong> पर क्लिक करें। यह कवर पृष्ठ, विषय-सूची और पृष्ठ संख्याओं के साथ एक स्वरूपित दस्तावेज़ तैयार करता है।</p></div></details>

        <details class="faq-item"><summary>मैं कैसे जानूं कि मैं किस संस्करण पर चल रहा हूं?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>प्रशासक <strong>Admin &rarr; Version</strong> जांच सकते हैं। यह मार्गदर्शिका <strong>v2.6.2</strong> का वर्णन करती है। देखें <a href="#admin-updates">प्लेटफ़ॉर्म अपडेट करना</a>।</p></div></details>

    </div>
    <p class="faq-no-results" id="faqNoResults">आपकी खोज से कोई प्रश्न मेल नहीं खाता। एक अलग कीवर्ड आज़माएं।</p>
</div>


<!-- ================================================================
     TROUBLESHOOTING
     ================================================================ -->
<div class="doc-section" id="troubleshooting">
    <h2>Troubleshooting</h2>

    <h3>खरीद के माध्यम से ऑनबोर्डिंग क्यों मायने रखती है (VID &amp; Procurement Onboarding नियम)</h3>
    <p>यह सबसे आम चीज़ है जो एक विक्रेता को आगे बढ़ने से रोकती है, इसलिए इसे समझना उचित है। प्लेटफ़ॉर्म <strong>तब तक एक विक्रेता को साइबर समीक्षा के लिए सबमिट करने की अनुमति नहीं देगा</strong> जब तक विक्रेता पर दो खरीद तथ्य दर्ज नहीं हैं:</p>
    <ul>
        <li><strong>Procurement Onboarding = Yes</strong> &mdash; पुष्टि कि विक्रेता को आपके संगठन की खरीद प्रक्रिया के माध्यम से स्थापित और सत्यापित किया गया है।</li>
        <li>एक वैध <strong>Vendor ID (VID)</strong> &mdash; 4&ndash;8 अंकीय संख्या जो खरीद विक्रेता को असाइन करती है।</li>
    </ul>
    <p>यह क्यों लागू करें? क्योंकि VID साझा कुंजी है जो इस विक्रेता को खरीद, वित्त और अनुबंध रिकॉर्ड से जोड़ती है। यदि साइबर टीम ने एक ऐसे विक्रेता की समीक्षा और अनुमोदन किया जिसे खरीद ने कभी ऑनबोर्ड नहीं किया था, तो आप डुप्लिकेट या "भूत" विक्रेताओं के साथ समाप्त होंगे, सुरक्षा कार्य जिसे वास्तविक खरीद आदेश से नहीं जोड़ा जा सकता, और ऐसी रिपोर्ट जो मेल नहीं खातीं। पहले खरीद ऑनबोर्डिंग आवश्यक करने से सुरक्षा समीक्षा और खरीद रिकॉर्ड दोनों एक ही, वास्तविक विक्रेता की ओर इशारा करते हैं।</p>
    <div class="callout callout-warning">
        <strong>इसे ठीक करें:</strong> विक्रेता खोलें, और <strong>Vendor Information</strong> कार्ड पर <strong>Procurement Onboarding</strong> को <strong>Yes</strong> पर सेट करें और अपने खरीद प्रणाली से 4&ndash;8 अंकीय <strong>Vendor ID (VID)</strong> दर्ज करें। सहेजें, फिर <strong>Submit for Review</strong> फिर से क्लिक करें। यदि आपके पास अभी तक VID नहीं है, तो विक्रेता ने खरीद ऑनबोर्डिंग पूरी नहीं की है &mdash; वहाँ से शुरू करें।
    </div>

    <h3>सामान्य समस्याएं और उन्हें कैसे हल करें</h3>
    <table class="doc-table">
        <tr><th>लक्षण</th><th>संभावित कारण &amp; सुधार</th></tr>
        <tr><td>"Cannot submit: Vendor must be onboarded at VSU before submission&hellip;"</td><td><strong>Procurement Onboarding</strong> फ़ील्ड <strong>Yes</strong> पर सेट नहीं है। Vendor Information कार्ड पर इसे Yes पर सेट करें और सहेजें।</td></tr>
        <tr><td>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits)&hellip;"</td><td><strong>Vendor ID</strong> गायब है या 4&ndash;8 अंक नहीं है। खरीद से एक वैध VID दर्ज करें।</td></tr>
        <tr><td>"Only draft requests can be submitted for review."</td><td>विक्रेता पहले से Draft से आगे है। आप केवल उस अनुरोध को सबमिट कर सकते हैं जो अभी भी <strong>Draft</strong> स्थिति में है।</td></tr>
        <tr><td><strong>Submit for Review</strong> बटन दिखाई नहीं दे रहा</td><td>यह केवल <strong>Draft</strong> में विक्रेताओं के लिए दिखाई देता है जब आपके पास संपादन अनुमति हो।</td></tr>
        <tr><td>"AI Review can only be forced for vendors whose services use AI."</td><td>AI Review बाध्य करने से पहले विक्रेता पर <strong>Services Use AI</strong> को <strong>Yes</strong> पर सेट करें।</td></tr>
        <tr><td>मुझे साइडबार में GRC मॉड्यूल नहीं दिख रहा</td><td>आपको <strong>Administrator</strong>, <strong>Cyber GRC</strong>, या <strong>Auditor</strong> समूह में होना चाहिए। किसी प्रशासक से पूछें।</td></tr>
        <tr><td>मेरी भाषा परिवर्तन नहीं टिकी</td><td>सुनिश्चित करें कि आपने <strong>Update Language</strong> पर क्लिक किया (न केवल ड्रॉप-डाउन बदला), और कि आपके प्रशासक द्वारा भाषा सक्षम है।</td></tr>
        <tr><td>Grip "Test Connection" विफल हुआ</td><td>जांचें कि बेस URL <code>/public/saas</code> में समाप्त होती है और API token वैध और वर्तमान है।</td></tr>
        <tr><td>Shadow SaaS ऐप को Deny करने से Zscaler में ब्लॉक नहीं हुआ</td><td>Zscaler ब्लॉकिंग सक्षम और कॉन्फ़िगर होनी चाहिए, और नामित <strong>URL Category</strong> Zscaler में पहले से मौजूद होनी चाहिए।</td></tr>
        <tr><td>खरीद को digest ईमेल नहीं मिली</td><td>पुष्टि करें कि <strong>Admin &rarr; Email Settings</strong> के अंतर्गत प्राप्तकर्ताओं के साथ digest सक्षम है, और कि <strong>Admin &rarr; Email</strong> SMTP सेटिंग सही हैं।</td></tr>
        <tr><td>Upgrade बटन "Could not fetch manifest" कहता है</td><td>आपकी image रजिस्ट्री तक पहुंचने में रजिस्ट्री/नेटवर्क समस्या। <strong>Admin &rarr; Version</strong> के अंतर्गत <strong>Registry Hostname</strong> सत्यापित करें और कि होस्ट इसे पहुंच सकता है।</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>अभी भी अटके हैं?</strong> स्क्रीन पर सटीक संदेश और आप किस पृष्ठ पर थे, नोट करें, फिर अपने प्लेटफ़ॉर्म प्रशासक से संपर्क करें। प्रशासक विवरण के लिए <strong>Admin &rarr; Activity Log</strong> की समीक्षा कर सकते हैं।
    </div>
</div>


<!-- ================================================================
     GLOSSARY
     ================================================================ -->
<div class="doc-section" id="glossary">
    <h2>शब्दावली</h2>
    <table class="doc-table">
        <tr><th>शब्द</th><th>परिभाषा</th></tr>
        <tr><td><strong>ACL</strong></td><td>Access Control List &mdash; परिभाषित करता है कि किसी समूह के उपयोगकर्ता कौन सी क्रियाएं कर सकते हैं</td></tr>
        <tr><td><strong>Action Plan</strong></td><td>अनुवर्ती क्रियाएं (संपर्क, मूल्यांकन भेजना, वार्षिक समीक्षा बाध्य करना) शेड्यूल करने के लिए एक प्रति-विक्रेता टैब, नियत तिथियों, स्वामियों और स्थिति नोट के साथ; Vendor Remediation Schedule जॉब द्वारा दैनिक फायर किया जाता है</td></tr>
        <tr><td><strong>AI Review</strong></td><td>उन विक्रेताओं के लिए एक vendor onboarding स्थिति जिनकी सेवाएं AI का उपयोग करती हैं, समीक्षा के दौरान अलग से ट्रैक की जाती हैं</td></tr>
        <tr><td><strong>Assessment</strong></td><td>एकीकृत प्रश्नावली का उपयोग करके एक समय-बिंदु अनुपालन मूल्यांकन</td></tr>
        <tr><td><strong>CIS Controls</strong></td><td>Center for Internet Security Controls &mdash; सुरक्षा सर्वोत्तम प्रथाओं का एक प्राथमिकता सेट</td></tr>
        <tr><td><strong>CMMC</strong></td><td>Cybersecurity Maturity Model Certification &mdash; US Department of Defense ठेकेदारों के लिए आवश्यक</td></tr>
        <tr><td><strong>Conformity Status</strong></td><td>क्या एक आवश्यकता Conforming, Partial, Non-Conforming, Not Applicable, या Not Assessed है</td></tr>
        <tr><td><strong>Control</strong></td><td>अनुपालन आवश्यकताओं को पूरा करने के लिए लागू एक विशिष्ट सुरक्षा उपाय</td></tr>
        <tr><td><strong>Crosswalk</strong></td><td>दो फ्रेमवर्क के बीच एक मैपिंग जो दिखाती है कि कौन सी आवश्यकताएं ओवरलैप होती हैं</td></tr>
        <tr><td><strong>CSF</strong></td><td>NIST Cybersecurity Framework &mdash; एक व्यापक रूप से उपयोग किया जाने वाला साइबरसुरक्षा जोखिम प्रबंधन फ्रेमवर्क</td></tr>
        <tr><td><strong>Custom Field / Custom Data</strong></td><td>बिना किसी मानक विक्रेता कॉलम वाला एक संगठन-विशिष्ट ऑनबोर्डिंग फ़ील्ड; प्रति विक्रेता कैप्चर किया गया और विक्रेता के Custom Data टैब पर दिखाया गया, प्रति-भूमिका दृश्यता के साथ (देखें <a href="#custom-onboarding">कस्टम ऑनबोर्डिंग फ़ील्ड</a>)</td></tr>
        <tr><td><strong>Domain</strong></td><td>सुरक्षा प्रश्नों की एक श्रेणी (जैसे, Governance, Identity &amp; Access Management)</td></tr>
        <tr><td><strong>Evidence</strong></td><td>दस्तावेज़, स्क्रीनशॉट या फ़ाइलें जो अनुपालन दावे को साबित करती हैं</td></tr>
        <tr><td><strong>FAIR</strong></td><td>Factor Analysis of Information Risk &mdash; एक मात्रात्मक जोखिम विश्लेषण पद्धति</td></tr>
        <tr><td><strong>FairScore</strong></td><td>मूल्यांकन प्रतिक्रियाओं से गणना किया गया प्लेटफ़ॉर्म का समग्र परिपक्वता स्कोर</td></tr>
        <tr><td><strong>Finding</strong></td><td>ऑडिट के दौरान खोजी गई एक समस्या (nonconformity, observation, opportunity, या strength)</td></tr>
        <tr><td><strong>Framework</strong></td><td>SOC 2, ISO 27001, PCI DSS, आदि जैसा एक अनुपालन मानक</td></tr>
        <tr><td><strong>GRC</strong></td><td>Governance, Risk, and Compliance</td></tr>
        <tr><td><strong>Grip</strong></td><td>Grip Security &mdash; एक सेवा जो उपयोग में SaaS ऐप्स खोजती है; Shadow SaaS सूची फीड कर सकती है (देखें <a href="#shadow-saas-grip">Grip Shadow SaaS Integration</a>)</td></tr>
        <tr><td><strong>Hero</strong></td><td>HERO Security &mdash; एक वैकल्पिक Shadow SaaS खोज सेवा जो Shadow SaaS सूची फीड कर सकती है (Grip के साथ परस्पर अनन्य; देखें <a href="#shadow-saas-hero">Hero Shadow SaaS Integration</a>)</td></tr>
        <tr><td><strong>HIPAA</strong></td><td>Health Insurance Portability and Accountability Act &mdash; US स्वास्थ्य सेवा डेटा संरक्षण कानून</td></tr>
        <tr><td><strong>ISO 27001</strong></td><td>सूचना सुरक्षा प्रबंधन प्रणालियों के लिए अंतरराष्ट्रीय मानक</td></tr>
        <tr><td><strong>Maturity Rating</strong></td><td>1-4 स्कोर जो बताता है कि एक सुरक्षा अभ्यास कितना परिपक्व है (1=Ad Hoc, 4=Optimized)</td></tr>
        <tr><td><strong>NIST 800-171</strong></td><td>Controlled Unclassified Information (CUI) की सुरक्षा के लिए NIST दिशानिर्देश</td></tr>
        <tr><td><strong>PCI DSS</strong></td><td>Payment Card Industry Data Security Standard</td></tr>
        <tr><td><strong>PII</strong></td><td>Personally Identifiable Information (नाम, ईमेल, पते, आदि)</td></tr>
        <tr><td><strong>Procurement Onboarding</strong></td><td>पुष्टि (Yes/No) कि एक विक्रेता को आपकी खरीद प्रक्रिया के माध्यम से स्थापित किया गया है; एक वैध VID के साथ, विक्रेता को समीक्षा के लिए सबमिट करने से पहले आवश्यक है। (पहले के रिलीज़ से अपग्रेड किए गए इंस्टेंस पर "VSU Onboarded" लेबल किया गया।)</td></tr>
        <tr><td><strong>Requirement</strong></td><td>एक अनुपालन फ्रेमवर्क के भीतर एक विशिष्ट खंड या नियंत्रण उद्देश्य</td></tr>
        <tr><td><strong>SaaS</strong></td><td>Software as a Service &mdash; वेब पर एक्सेस किए गए क्लाउड एप्लिकेशन</td></tr>
        <tr><td><strong>Shadow SaaS</strong></td><td>संगठन में उपयोग किए जाने वाले SaaS ऐप्स जिन्हें कभी औपचारिक रूप से अनुमोदित या मूल्यांकन नहीं किया गया</td></tr>
        <tr><td><strong>SOC 2</strong></td><td>Service Organization Control Type 2 &mdash; सेवा संगठनों के लिए trust services मानदंड</td></tr>
        <tr><td><strong>SPII</strong></td><td>Sensitive PII (SSN, वित्तीय डेटा, स्वास्थ्य रिकॉर्ड)</td></tr>
        <tr><td><strong>SRS</strong></td><td>Security Risk Scorecard &mdash; एक विक्रेता के लिए प्लेटफ़ॉर्म की बाहरी सुरक्षा रेटिंग/ग्रेड</td></tr>
        <tr><td><strong>SSC (SecurityScorecard)</strong></td><td>एक तृतीय-पक्ष सुरक्षा अक्षर-ग्रेड (A&ndash;F) जो Grip कनेक्ट होने पर Grip-खोजे गए ऐप्स और विक्रेताओं के लिए दिखाया जाता है</td></tr>
        <tr><td><strong>Subprocessor</strong></td><td>एक विक्रेता का अपना downstream विक्रेता; आपके कई विक्रेताओं में साझा किया गया वही subprocessor आपूर्ति-श्रृंखला concentration को इंगित करता है (देखें <a href="#tprm-fourth-party">4th Party Risk</a>)</td></tr>
        <tr><td><strong>TPRM</strong></td><td>Third Party Risk Management</td></tr>
        <tr><td><strong>Unified Question</strong></td><td>एक एकल सुरक्षा प्रश्न जो एकाधिक फ्रेमवर्क में आवश्यकताओं से मैप होता है</td></tr>
        <tr><td><strong>VID</strong></td><td>Vendor ID &mdash; आपकी खरीद प्रणाली द्वारा एक विक्रेता को असाइन किया गया 4&ndash;8 अंकीय पहचानकर्ता</td></tr>
        <tr><td><strong>VSU</strong></td><td>खरीद/विक्रेता-सेटअप कार्य; "onboarded at VSU" का अर्थ है कि विक्रेता ने Procurement Onboarding पूरी कर ली है</td></tr>
        <tr><td><strong>Zscaler</strong></td><td>एक वेब-सुरक्षा सेवा जो वेबसाइट डोमेन ब्लॉक कर सकती है; एकीकृत ताकि Deny पर अनुचित ऐप्स ब्लॉक किए जा सकें (देखें <a href="#zscaler">Zscaler Blocking</a>)</td></tr>
    </table>
</div>


                </div><!-- /.doc-body -->

                <!-- Sticky right-side TOC (screen only) -->
                <nav class="doc-sidebar-toc" id="sidebarToc">
                    <h4>आरंभ करना</h4>
                    <a href="#overview">प्लेटफ़ॉर्म अवलोकन</a>
                    <a href="#navigation">साइडबार नेविगेट करना</a>
                    <a href="#roles">उपयोगकर्ता भूमिकाएं &amp; अनुमतियाँ</a>
                    <a href="#first-login">आपका पहला लॉगिन</a>
                    <a href="#whats-new">2.6.2 में क्या नया है</a>
                    <a href="#language">अपनी भाषा बदलना</a>
                    <a href="#question-types">Phone &amp; VAT प्रश्न प्रकार</a>

                    <h4>GRC — त्वरित प्रारंभ</h4>
                    <a href="#grc-overview">GRC क्या है?</a>
                    <a href="#grc-getting-started">आरंभ करना</a>
                    <a href="#grc-step1">चरण 1: मूल्यांकन बनाएं</a>
                    <a href="#grc-step2">चरण 2: प्रश्नों के उत्तर दें</a>
                    <a href="#grc-step3">चरण 3: साक्ष्य अपलोड करें</a>
                    <a href="#grc-step4">चरण 4: स्कोर देखें</a>
                    <a href="#grc-step5">चरण 5: रिपोर्ट बनाएं</a>

                    <h4>GRC — सुविधाएं</h4>
                    <a href="#grc-fairscore">CSF Maturity Score</a>
                    <a href="#grc-gaps">Gap Analysis</a>
                    <a href="#grc-frameworks">Frameworks</a>
                    <a href="#grc-controls">आंतरिक नियंत्रण</a>
                    <a href="#grc-crosswalk">Framework Crosswalk</a>
                    <a href="#grc-evidence">Evidence Library</a>
                    <a href="#grc-policies">Policy Management</a>
                    <a href="#grc-audits">Audits &amp; Findings</a>
                    <a href="#grc-risks">Risk Register</a>
                    <a href="#grc-monitors">Continuous Monitors</a>
                    <a href="#grc-tasks">Task Inbox</a>
                    <a href="#grc-dashboard">GRC Dashboard</a>

                    <h4>TPRM मॉड्यूल</h4>
                    <a href="#tprm-overview">TPRM क्या है?</a>
                    <a href="#tprm-add-vendor">विक्रेता जोड़ना</a>
                    <a href="#tprm-lifecycle">विक्रेता जीवनचक्र</a>
                    <a href="#tprm-assessments">विक्रेता मूल्यांकन</a>
                    <a href="#assessment-forms">मूल्यांकन फ़ॉर्म &amp; AI फ़िल</a>
                    <a href="#tprm-action-plan">Vendor Action Plan</a>
                    <a href="#tprm-srs">Security Risk Scorecard</a>
                    <a href="#tprm-fair">FAIR Analysis</a>
                    <a href="#tprm-fourth-party">4th Party Risk</a>
                    <a href="#tprm-shadow-saas">Shadow SaaS</a>

                    <h4>ऑनबोर्डिंग &amp; Procurement</h4>
                    <a href="#onboarding-workflow">विक्रेता ऑनबोर्डिंग</a>
                    <a href="#custom-onboarding">कस्टम ऑनबोर्डिंग फ़ील्ड</a>
                    <a href="#ai-review">AI Review</a>
                    <a href="#procurement-cyber-status">Procurement Cyber Status</a>
                    <a href="#shadow-saas-grip">Grip Shadow SaaS Integration</a>
                    <a href="#shadow-saas-hero">Hero Shadow SaaS Integration</a>
                    <a href="#zscaler">Zscaler Blocking</a>
                    <a href="#breach-alerts">Breach / Cyber Alerts</a>

                    <h4>Admin Portal</h4>
                    <a href="#admin-general">सामान्य सेटिंग</a>
                    <a href="#admin-branding">Branding &amp; Theme</a>
                    <a href="#admin-users">User Management</a>
                    <a href="#admin-acl-groups">ACL Groups</a>
                    <a href="#admin-templates">Assessment Templates</a>
                    <a href="#admin-email">Email Configuration</a>
                    <a href="#admin-saml">SAML / SSO</a>
                    <a href="#admin-ai">AI Integration</a>
                    <a href="#admin-backup">बड़े डेटाबेस बैकअप</a>
                    <a href="#admin-updates">प्लेटफ़ॉर्म अपडेट करना</a>

                    <h4>सहायता &amp; संदर्भ</h4>
                    <a href="#faq">अक्सर पूछे जाने वाले प्रश्न</a>
                    <a href="#troubleshooting">Troubleshooting</a>
                    <a href="#glossary">शब्दावली</a>
                </nav>

