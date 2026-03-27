<?php
/**
 * FairScore Unified Question → Framework Requirement Mappings
 *
 * Author: Tim Rice - Hack Range
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Maps each unified question to specific framework requirements.
 * Used to populate grc_question_framework_map table.
 *
 * Format: 'QUESTION_REF' => [['f'=>'FRAMEWORK_CODE', 'r'=>'REQ_REF', 's'=>'exact|strong|partial|related'], ...]
 * Frameworks: SOC2, ISO27001, CSF, CMMC, NIST800171, PCI_DSS, SOX, HIPAA, CIS
 */

return [
// ========================= GOV =========================
'GOV-01' => [
    ['f'=>'SOC2','r'=>'CC1.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.1','s'=>'exact'],['f'=>'CSF','r'=>'GV.PO-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.12.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.1','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.308(a)(1)(i)','s'=>'strong'],['f'=>'CIS','r'=>'1.1','s'=>'partial'],
],
'GOV-02' => [
    ['f'=>'SOC2','r'=>'CC1.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.2','s'=>'exact'],['f'=>'CSF','r'=>'GV.RR-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.1','s'=>'partial'],['f'=>'NIST800171','r'=>'3.12.1','s'=>'partial'],['f'=>'PCI_DSS','r'=>'12.1.1','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.308(a)(2)','s'=>'exact'],['f'=>'SOX','r'=>'ITGC-SM-01','s'=>'strong'],
],
'GOV-03' => [
    ['f'=>'SOC2','r'=>'CC1.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.4','s'=>'strong'],['f'=>'CSF','r'=>'GV.RR-02','s'=>'exact'],
    ['f'=>'CSF','r'=>'GV.OC-03','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.4','s'=>'strong'],['f'=>'HIPAA','r'=>'164.308(a)(1)(i)','s'=>'partial'],
],
'GOV-04' => [
    ['f'=>'SOC2','r'=>'CC1.3','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.1','s'=>'partial'],['f'=>'CSF','r'=>'GV.OC-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.4','s'=>'strong'],['f'=>'NIST800171','r'=>'3.12.4','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.1','s'=>'partial'],
],
'GOV-05' => [
    ['f'=>'SOC2','r'=>'CC3.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.7','s'=>'strong'],['f'=>'CSF','r'=>'GV.RM-01','s'=>'exact'],
    ['f'=>'CSF','r'=>'GV.RM-02','s'=>'exact'],['f'=>'CMMC','r'=>'RA.L2-3.11.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.11.1','s'=>'exact'],
    ['f'=>'PCI_DSS','r'=>'12.2','s'=>'strong'],['f'=>'HIPAA','r'=>'164.308(a)(1)(ii)(A)','s'=>'exact'],['f'=>'SOX','r'=>'ITGC-SM-01','s'=>'strong'],
],
'GOV-06' => [
    ['f'=>'SOC2','r'=>'CC3.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.31','s'=>'exact'],['f'=>'CSF','r'=>'GV.OC-02','s'=>'exact'],
    ['f'=>'CSF','r'=>'GV.OC-03','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.1','s'=>'partial'],['f'=>'SOX','r'=>'ITGC-SM-02','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.316(a)','s'=>'strong'],
],
'GOV-07' => [
    ['f'=>'SOC2','r'=>'CC1.3','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.1','s'=>'partial'],['f'=>'CSF','r'=>'GV.RR-03','s'=>'exact'],
    ['f'=>'CSF','r'=>'GV.RR-04','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.1','s'=>'partial'],
],
'GOV-08' => [
    ['f'=>'SOC2','r'=>'CC1.2','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.4','s'=>'strong'],['f'=>'CSF','r'=>'GV.RR-01','s'=>'partial'],
    ['f'=>'CSF','r'=>'GV.OC-04','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.4','s'=>'partial'],
],
'GOV-09' => [
    ['f'=>'SOC2','r'=>'CC4.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.35','s'=>'strong'],['f'=>'CSF','r'=>'GV.OC-04','s'=>'exact'],
    ['f'=>'CSF','r'=>'ID.IM-01','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.4','s'=>'partial'],['f'=>'SOX','r'=>'ITGC-SM-03','s'=>'strong'],
],
'GOV-10' => [
    ['f'=>'SOC2','r'=>'CC1.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.4','s'=>'partial'],['f'=>'CSF','r'=>'GV.PO-02','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.6','s'=>'partial'],['f'=>'HIPAA','r'=>'164.308(a)(1)(ii)(C)','s'=>'partial'],
],
'GOV-11' => [
    ['f'=>'SOC2','r'=>'CC1.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.1','s'=>'partial'],['f'=>'CSF','r'=>'GV.PO-01','s'=>'partial'],
    ['f'=>'CSF','r'=>'GV.RM-07','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.1','s'=>'partial'],
],
'GOV-12' => [
    ['f'=>'SOC2','r'=>'CC1.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.8','s'=>'exact'],['f'=>'CSF','r'=>'GV.OC-05','s'=>'exact'],
    ['f'=>'CSF','r'=>'GV.PO-02','s'=>'strong'],['f'=>'SOX','r'=>'ITGC-SM-02','s'=>'partial'],
],

// ========================= IAM =========================
'IAM-01' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.18','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'7.1','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'8.1','s'=>'strong'],['f'=>'HIPAA','r'=>'164.312(a)(2)(i)','s'=>'strong'],['f'=>'CIS','r'=>'5.1','s'=>'strong'],
],
'IAM-02' => [
    ['f'=>'SOC2','r'=>'CC6.3','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.15','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-05','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.5','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.5','s'=>'exact'],['f'=>'PCI_DSS','r'=>'7.1','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.312(a)(1)','s'=>'strong'],['f'=>'CIS','r'=>'6.1','s'=>'strong'],['f'=>'SOX','r'=>'ITGC-AC-01','s'=>'exact'],
],
'IAM-03' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.5','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-03','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IA.L2-3.5.3','s'=>'exact'],['f'=>'NIST800171','r'=>'3.5.3','s'=>'exact'],['f'=>'PCI_DSS','r'=>'8.3','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.312(d)','s'=>'strong'],['f'=>'CIS','r'=>'6.3','s'=>'exact'],
],
'IAM-04' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.2','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-05','s'=>'strong'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.7','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.7','s'=>'exact'],['f'=>'PCI_DSS','r'=>'8.6','s'=>'strong'],
    ['f'=>'CIS','r'=>'6.5','s'=>'exact'],['f'=>'SOX','r'=>'ITGC-AC-02','s'=>'exact'],
],
'IAM-05' => [
    ['f'=>'SOC2','r'=>'CC6.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.18','s'=>'strong'],['f'=>'CSF','r'=>'PR.AA-05','s'=>'strong'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.1','s'=>'partial'],['f'=>'NIST800171','r'=>'3.1.1','s'=>'partial'],['f'=>'PCI_DSS','r'=>'7.2','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.312(a)(1)','s'=>'partial'],['f'=>'CIS','r'=>'5.1','s'=>'partial'],['f'=>'SOX','r'=>'ITGC-AC-03','s'=>'exact'],
],
'IAM-06' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.17','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-04','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IA.L2-3.5.7','s'=>'exact'],['f'=>'NIST800171','r'=>'3.5.7','s'=>'exact'],['f'=>'PCI_DSS','r'=>'8.2','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.312(a)(2)(i)','s'=>'strong'],['f'=>'CIS','r'=>'5.2','s'=>'exact'],
],
'IAM-07' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.8.5','s'=>'partial'],['f'=>'CSF','r'=>'PR.AA-03','s'=>'strong'],
    ['f'=>'CMMC','r'=>'IA.L2-3.5.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.5.1','s'=>'strong'],['f'=>'CIS','r'=>'6.7','s'=>'strong'],
],
'IAM-08' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.18','s'=>'strong'],['f'=>'CSF','r'=>'PR.AA-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.1.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'8.6','s'=>'exact'],
    ['f'=>'CIS','r'=>'5.4','s'=>'exact'],
],
'IAM-09' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.1','s'=>'strong'],['f'=>'CSF','r'=>'PR.AA-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.12','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.12','s'=>'exact'],['f'=>'PCI_DSS','r'=>'8.3','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.312(e)(1)','s'=>'strong'],['f'=>'CIS','r'=>'6.4','s'=>'strong'],
],
'IAM-10' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.16','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'IA.L2-3.5.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.5.1','s'=>'exact'],['f'=>'CIS','r'=>'5.1','s'=>'strong'],
],
'IAM-11' => [
    ['f'=>'SOC2','r'=>'CC6.3','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.15','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-05','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'7.2','s'=>'exact'],
    ['f'=>'SOX','r'=>'ITGC-AC-01','s'=>'strong'],['f'=>'CIS','r'=>'6.1','s'=>'strong'],
],
'IAM-12' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.8.1','s'=>'partial'],['f'=>'CSF','r'=>'PR.AA-06','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.10','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.10','s'=>'exact'],['f'=>'PCI_DSS','r'=>'8.2.8','s'=>'strong'],
    ['f'=>'CIS','r'=>'5.6','s'=>'strong'],
],
'IAM-13' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.8.2','s'=>'strong'],['f'=>'CSF','r'=>'PR.AA-05','s'=>'partial'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.1','s'=>'partial'],['f'=>'NIST800171','r'=>'3.1.1','s'=>'partial'],['f'=>'PCI_DSS','r'=>'8.6','s'=>'partial'],
],
'IAM-14' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.5','s'=>'strong'],['f'=>'CSF','r'=>'PR.AA-04','s'=>'strong'],
    ['f'=>'CMMC','r'=>'IA.L2-3.5.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.5.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'8.3','s'=>'strong'],
    ['f'=>'CIS','r'=>'5.3','s'=>'strong'],
],

// ========================= DSP =========================
'DSP-01' => [
    ['f'=>'SOC2','r'=>'CC6.5','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.12','s'=>'exact'],['f'=>'CSF','r'=>'ID.AM-07','s'=>'exact'],
    ['f'=>'CMMC','r'=>'MP.L2-3.8.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.8.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'3.2','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.312(a)(1)','s'=>'partial'],['f'=>'CIS','r'=>'3.1','s'=>'exact'],
],
'DSP-02' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.16','s'=>'exact'],['f'=>'NIST800171','r'=>'3.13.16','s'=>'exact'],['f'=>'PCI_DSS','r'=>'3.5','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.312(a)(2)(iv)','s'=>'exact'],['f'=>'CIS','r'=>'3.6','s'=>'exact'],
],
'DSP-03' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'strong'],['f'=>'CSF','r'=>'PR.DS-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.8','s'=>'exact'],['f'=>'NIST800171','r'=>'3.13.8','s'=>'exact'],['f'=>'PCI_DSS','r'=>'4.1','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.312(e)(1)','s'=>'exact'],['f'=>'CIS','r'=>'3.10','s'=>'exact'],
],
'DSP-04' => [
    ['f'=>'SOC2','r'=>'CC6.5','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.12','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-10','s'=>'strong'],
    ['f'=>'CMMC','r'=>'MP.L2-3.8.3','s'=>'strong'],['f'=>'NIST800171','r'=>'3.8.3','s'=>'strong'],['f'=>'PCI_DSS','r'=>'3.3','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.312(c)(1)','s'=>'strong'],['f'=>'CIS','r'=>'3.13','s'=>'exact'],
],
'DSP-05' => [
    ['f'=>'SOC2','r'=>'P1.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.34','s'=>'exact'],['f'=>'CSF','r'=>'GV.OC-02','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.1','s'=>'partial'],['f'=>'HIPAA','r'=>'164.308(a)(1)(ii)(A)','s'=>'strong'],
],
'DSP-06' => [
    ['f'=>'SOC2','r'=>'CC6.5','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.33','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-10','s'=>'strong'],
    ['f'=>'CMMC','r'=>'MP.L2-3.8.3','s'=>'exact'],['f'=>'NIST800171','r'=>'3.8.3','s'=>'exact'],['f'=>'PCI_DSS','r'=>'3.1','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.310(d)(2)(i)','s'=>'exact'],['f'=>'CIS','r'=>'3.3','s'=>'strong'],
],
'DSP-07' => [
    ['f'=>'SOC2','r'=>'P1.1','s'=>'exact'],['f'=>'SOC2','r'=>'P1.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.34','s'=>'exact'],
    ['f'=>'CSF','r'=>'GV.OC-02','s'=>'strong'],['f'=>'PCI_DSS','r'=>'3.2','s'=>'partial'],['f'=>'HIPAA','r'=>'164.530(c)','s'=>'exact'],
],
'DSP-08' => [
    ['f'=>'SOC2','r'=>'CC6.5','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.11','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'3.4','s'=>'strong'],['f'=>'HIPAA','r'=>'164.514(a)','s'=>'strong'],['f'=>'CIS','r'=>'3.12','s'=>'strong'],
],
'DSP-09' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.3','s'=>'strong'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.3','s'=>'strong'],['f'=>'NIST800171','r'=>'3.1.3','s'=>'strong'],['f'=>'PCI_DSS','r'=>'6.2','s'=>'strong'],
    ['f'=>'CIS','r'=>'3.3','s'=>'partial'],
],
'DSP-10' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.13','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-09','s'=>'exact'],
    ['f'=>'CMMC','r'=>'MP.L2-3.8.9','s'=>'strong'],['f'=>'NIST800171','r'=>'3.8.9','s'=>'strong'],['f'=>'PCI_DSS','r'=>'9.5','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.308(a)(7)(ii)(A)','s'=>'exact'],['f'=>'CIS','r'=>'11.1','s'=>'exact'],
],
'DSP-11' => [
    ['f'=>'SOC2','r'=>'P1.6','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.35','s'=>'exact'],['f'=>'CSF','r'=>'GV.OC-02','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'12.1','s'=>'partial'],['f'=>'HIPAA','r'=>'164.312(e)(1)','s'=>'partial'],
],
'DSP-12' => [
    ['f'=>'SOC2','r'=>'CC6.5','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.33','s'=>'strong'],['f'=>'CSF','r'=>'PR.DS-10','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'12.3','s'=>'partial'],['f'=>'HIPAA','r'=>'164.316(b)(2)(i)','s'=>'exact'],['f'=>'SOX','r'=>'ITGC-SM-02','s'=>'strong'],
],

// ========================= EPS =========================
'EPS-01' => [
    ['f'=>'SOC2','r'=>'CC6.8','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.7','s'=>'exact'],['f'=>'CSF','r'=>'DE.CM-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SI.L1-b.1.xi','s'=>'exact'],['f'=>'NIST800171','r'=>'3.14.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'5.2','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.308(a)(5)(ii)(B)','s'=>'strong'],['f'=>'CIS','r'=>'10.1','s'=>'exact'],
],
'EPS-02' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.9','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CM.L2-3.4.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.4.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'2.2','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.312(a)(1)','s'=>'partial'],['f'=>'CIS','r'=>'4.1','s'=>'exact'],
],
'EPS-03' => [
    ['f'=>'SOC2','r'=>'CC7.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.8','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SI.L2-3.14.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.14.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'6.3','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.308(a)(5)(ii)(B)','s'=>'partial'],['f'=>'CIS','r'=>'7.1','s'=>'exact'],
],
'EPS-04' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.1','s'=>'strong'],['f'=>'CSF','r'=>'PR.PS-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.18','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.18','s'=>'exact'],['f'=>'PCI_DSS','r'=>'9.5','s'=>'partial'],
    ['f'=>'CIS','r'=>'4.3','s'=>'strong'],
],
'EPS-05' => [
    ['f'=>'SOC2','r'=>'CC6.5','s'=>'strong'],['f'=>'ISO27001','r'=>'A.7.10','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'MP.L2-3.8.7','s'=>'exact'],['f'=>'NIST800171','r'=>'3.8.7','s'=>'exact'],['f'=>'PCI_DSS','r'=>'9.4','s'=>'strong'],
    ['f'=>'CIS','r'=>'10.3','s'=>'strong'],
],
'EPS-06' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.9','s'=>'exact'],['f'=>'CSF','r'=>'ID.AM-01','s'=>'exact'],
    ['f'=>'CSF','r'=>'ID.AM-02','s'=>'exact'],['f'=>'CMMC','r'=>'CM.L2-3.4.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.4.1','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'2.4','s'=>'exact'],['f'=>'HIPAA','r'=>'164.310(d)(1)','s'=>'strong'],['f'=>'CIS','r'=>'1.1','s'=>'exact'],
],
'EPS-07' => [
    ['f'=>'SOC2','r'=>'CC6.8','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.19','s'=>'strong'],['f'=>'CSF','r'=>'PR.PS-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'CM.L2-3.4.8','s'=>'exact'],['f'=>'NIST800171','r'=>'3.4.8','s'=>'exact'],['f'=>'PCI_DSS','r'=>'6.4','s'=>'strong'],
    ['f'=>'CIS','r'=>'2.5','s'=>'exact'],
],
'EPS-08' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.9','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CM.L2-3.4.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.4.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'2.2','s'=>'exact'],
    ['f'=>'CIS','r'=>'4.1','s'=>'strong'],
],
'EPS-09' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.13','s'=>'strong'],['f'=>'CSF','r'=>'PR.DS-09','s'=>'strong'],
    ['f'=>'CMMC','r'=>'MP.L2-3.8.9','s'=>'strong'],['f'=>'NIST800171','r'=>'3.8.9','s'=>'strong'],['f'=>'CIS','r'=>'11.1','s'=>'partial'],
],
'EPS-10' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.8.1','s'=>'partial'],['f'=>'CSF','r'=>'PR.PS-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.12','s'=>'partial'],['f'=>'NIST800171','r'=>'3.1.12','s'=>'partial'],['f'=>'CIS','r'=>'4.1','s'=>'partial'],
],

// ========================= NET =========================
'NET-01' => [
    ['f'=>'SOC2','r'=>'CC6.6','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.22','s'=>'exact'],['f'=>'CSF','r'=>'PR.IR-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.5','s'=>'exact'],['f'=>'NIST800171','r'=>'3.13.5','s'=>'exact'],['f'=>'PCI_DSS','r'=>'1.2','s'=>'exact'],
    ['f'=>'CIS','r'=>'12.2','s'=>'exact'],
],
'NET-02' => [
    ['f'=>'SOC2','r'=>'CC6.6','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.20','s'=>'exact'],['f'=>'CSF','r'=>'PR.IR-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.13.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'1.1','s'=>'exact'],
    ['f'=>'CIS','r'=>'4.4','s'=>'exact'],
],
'NET-03' => [
    ['f'=>'SOC2','r'=>'CC7.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.16','s'=>'exact'],['f'=>'CSF','r'=>'DE.CM-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SI.L2-3.14.6','s'=>'exact'],['f'=>'NIST800171','r'=>'3.14.6','s'=>'exact'],['f'=>'PCI_DSS','r'=>'11.4','s'=>'exact'],
    ['f'=>'CIS','r'=>'13.3','s'=>'exact'],
],
'NET-04' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.1','s'=>'strong'],['f'=>'CSF','r'=>'PR.AA-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.12','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.12','s'=>'exact'],['f'=>'PCI_DSS','r'=>'8.3','s'=>'partial'],
    ['f'=>'CIS','r'=>'6.4','s'=>'strong'],
],
'NET-05' => [
    ['f'=>'SOC2','r'=>'CC6.6','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.20','s'=>'strong'],['f'=>'CSF','r'=>'PR.IR-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.16','s'=>'exact'],['f'=>'NIST800171','r'=>'3.1.16','s'=>'exact'],['f'=>'PCI_DSS','r'=>'2.3','s'=>'exact'],
    ['f'=>'CIS','r'=>'12.6','s'=>'exact'],
],
'NET-06' => [
    ['f'=>'SOC2','r'=>'CC6.6','s'=>'partial'],['f'=>'ISO27001','r'=>'A.8.20','s'=>'partial'],['f'=>'CSF','r'=>'PR.DS-10','s'=>'partial'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.1','s'=>'partial'],['f'=>'NIST800171','r'=>'3.13.1','s'=>'partial'],['f'=>'CIS','r'=>'9.2','s'=>'exact'],
],
'NET-07' => [
    ['f'=>'SOC2','r'=>'CC7.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.16','s'=>'exact'],['f'=>'CSF','r'=>'DE.CM-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SI.L2-3.14.6','s'=>'strong'],['f'=>'NIST800171','r'=>'3.14.6','s'=>'strong'],['f'=>'PCI_DSS','r'=>'10.6','s'=>'strong'],
    ['f'=>'CIS','r'=>'13.1','s'=>'exact'],
],
'NET-08' => [
    ['f'=>'SOC2','r'=>'A1.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.20','s'=>'partial'],['f'=>'CSF','r'=>'PR.IR-01','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'1.3','s'=>'partial'],['f'=>'CIS','r'=>'13.10','s'=>'strong'],
],
'NET-09' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.20','s'=>'strong'],['f'=>'CSF','r'=>'PR.AA-02','s'=>'strong'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.12','s'=>'strong'],['f'=>'NIST800171','r'=>'3.1.12','s'=>'strong'],['f'=>'PCI_DSS','r'=>'1.2','s'=>'strong'],
    ['f'=>'CIS','r'=>'13.7','s'=>'exact'],
],
'NET-10' => [
    ['f'=>'SOC2','r'=>'CC6.6','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.37','s'=>'strong'],['f'=>'CSF','r'=>'ID.AM-04','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.1','s'=>'partial'],['f'=>'PCI_DSS','r'=>'1.1.2','s'=>'exact'],['f'=>'CIS','r'=>'12.4','s'=>'strong'],
],
'NET-11' => [
    ['f'=>'SOC2','r'=>'CC6.6','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.22','s'=>'strong'],['f'=>'CSF','r'=>'PR.IR-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.5','s'=>'strong'],['f'=>'NIST800171','r'=>'3.13.5','s'=>'strong'],['f'=>'PCI_DSS','r'=>'1.2','s'=>'strong'],
    ['f'=>'CIS','r'=>'12.2','s'=>'strong'],
],

// ========================= APS =========================
'APS-01' => [
    ['f'=>'SOC2','r'=>'CC8.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.25','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-06','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SA.L2-3.13.2','s'=>'strong'],['f'=>'PCI_DSS','r'=>'6.2','s'=>'exact'],['f'=>'CIS','r'=>'16.1','s'=>'exact'],
],
'APS-02' => [
    ['f'=>'SOC2','r'=>'CC8.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.28','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-06','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'6.2.2','s'=>'exact'],['f'=>'CIS','r'=>'16.4','s'=>'exact'],
],
'APS-03' => [
    ['f'=>'SOC2','r'=>'CC8.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.29','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-06','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'6.2.4','s'=>'exact'],['f'=>'CIS','r'=>'16.5','s'=>'exact'],
],
'APS-04' => [
    ['f'=>'SOC2','r'=>'CC6.6','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.20','s'=>'strong'],['f'=>'CSF','r'=>'DE.CM-06','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'6.4.1','s'=>'exact'],['f'=>'CIS','r'=>'13.10','s'=>'strong'],
],
'APS-05' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.26','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-04','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'6.2.3','s'=>'strong'],['f'=>'CIS','r'=>'16.8','s'=>'exact'],
],
'APS-06' => [
    ['f'=>'SOC2','r'=>'CC8.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.28','s'=>'strong'],['f'=>'CSF','r'=>'GV.SC-09','s'=>'strong'],
    ['f'=>'CMMC','r'=>'SI.L2-3.14.1','s'=>'partial'],['f'=>'PCI_DSS','r'=>'6.3','s'=>'strong'],['f'=>'CIS','r'=>'16.2','s'=>'exact'],
],
'APS-07' => [
    ['f'=>'SOC2','r'=>'CC8.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.32','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-05','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CM.L2-3.4.3','s'=>'exact'],['f'=>'NIST800171','r'=>'3.4.3','s'=>'exact'],['f'=>'PCI_DSS','r'=>'6.5','s'=>'exact'],
    ['f'=>'SOX','r'=>'ITGC-CM-01','s'=>'exact'],['f'=>'CIS','r'=>'2.4','s'=>'strong'],
],
'APS-08' => [
    ['f'=>'SOC2','r'=>'CC8.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.31','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-05','s'=>'strong'],
    ['f'=>'CMMC','r'=>'CM.L2-3.4.4','s'=>'exact'],['f'=>'NIST800171','r'=>'3.4.4','s'=>'exact'],['f'=>'PCI_DSS','r'=>'6.5','s'=>'strong'],
    ['f'=>'SOX','r'=>'ITGC-CM-02','s'=>'strong'],
],
'APS-09' => [
    ['f'=>'SOC2','r'=>'CC8.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.28','s'=>'strong'],['f'=>'CSF','r'=>'PR.PS-06','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'6.2.1','s'=>'exact'],['f'=>'CIS','r'=>'16.9','s'=>'strong'],
],
'APS-10' => [
    ['f'=>'SOC2','r'=>'CC7.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.8','s'=>'strong'],['f'=>'CSF','r'=>'ID.RA-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'RA.L2-3.11.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.11.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'11.3','s'=>'exact'],
    ['f'=>'CIS','r'=>'7.1','s'=>'strong'],
],

// ========================= OPS =========================
'OPS-01' => [
    ['f'=>'SOC2','r'=>'CC7.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.15','s'=>'exact'],['f'=>'CSF','r'=>'DE.AE-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AU.L2-3.3.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.3.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'10.2','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.312(b)','s'=>'exact'],['f'=>'CIS','r'=>'8.2','s'=>'exact'],
],
'OPS-02' => [
    ['f'=>'SOC2','r'=>'CC7.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.16','s'=>'strong'],['f'=>'CSF','r'=>'DE.CM-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'AU.L2-3.3.1','s'=>'partial'],['f'=>'NIST800171','r'=>'3.3.1','s'=>'partial'],['f'=>'PCI_DSS','r'=>'10.6','s'=>'strong'],
    ['f'=>'CIS','r'=>'8.11','s'=>'exact'],
],
'OPS-03' => [
    ['f'=>'SOC2','r'=>'CC7.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.15','s'=>'exact'],['f'=>'CSF','r'=>'DE.AE-05','s'=>'strong'],
    ['f'=>'CMMC','r'=>'AU.L2-3.3.8','s'=>'exact'],['f'=>'NIST800171','r'=>'3.3.8','s'=>'exact'],['f'=>'PCI_DSS','r'=>'10.5','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.312(b)','s'=>'strong'],['f'=>'CIS','r'=>'8.1','s'=>'exact'],
],
'OPS-04' => [
    ['f'=>'SOC2','r'=>'CC7.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.8','s'=>'exact'],['f'=>'CSF','r'=>'ID.RA-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'RA.L2-3.11.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.11.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'11.3','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.308(a)(1)(ii)(A)','s'=>'strong'],['f'=>'CIS','r'=>'7.1','s'=>'exact'],
],
'OPS-05' => [
    ['f'=>'SOC2','r'=>'CC7.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.8','s'=>'strong'],['f'=>'CSF','r'=>'PR.PS-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'SI.L2-3.14.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.14.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'6.3','s'=>'exact'],
    ['f'=>'CIS','r'=>'7.4','s'=>'exact'],
],
'OPS-06' => [
    ['f'=>'SOC2','r'=>'CC3.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.7','s'=>'exact'],['f'=>'CSF','r'=>'ID.RA-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'RA.L2-3.11.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.11.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'6.1','s'=>'partial'],
    ['f'=>'CIS','r'=>'13.2','s'=>'strong'],
],
'OPS-07' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.9','s'=>'strong'],['f'=>'CSF','r'=>'ID.AM-01','s'=>'partial'],
    ['f'=>'CIS','r'=>'1.1','s'=>'partial'],
],
'OPS-08' => [
    ['f'=>'SOC2','r'=>'CC8.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.9','s'=>'exact'],['f'=>'CSF','r'=>'PR.PS-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CM.L2-3.4.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.4.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'2.2','s'=>'strong'],
    ['f'=>'CIS','r'=>'4.1','s'=>'strong'],
],
'OPS-09' => [
    ['f'=>'SOC2','r'=>'A1.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.6','s'=>'exact'],['f'=>'CSF','r'=>'ID.AM-03','s'=>'strong'],
    ['f'=>'CIS','r'=>'1.1','s'=>'partial'],
],
'OPS-10' => [
    ['f'=>'SOC2','r'=>'CC7.2','s'=>'partial'],['f'=>'ISO27001','r'=>'A.8.17','s'=>'exact'],['f'=>'CSF','r'=>'DE.AE-05','s'=>'partial'],
    ['f'=>'CMMC','r'=>'AU.L2-3.3.7','s'=>'exact'],['f'=>'NIST800171','r'=>'3.3.7','s'=>'exact'],['f'=>'PCI_DSS','r'=>'10.4','s'=>'exact'],
    ['f'=>'CIS','r'=>'8.4','s'=>'exact'],
],
'OPS-11' => [
    ['f'=>'SOC2','r'=>'CC4.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.34','s'=>'exact'],['f'=>'CSF','r'=>'ID.RA-06','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.12.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'11.4','s'=>'exact'],
    ['f'=>'CIS','r'=>'18.1','s'=>'exact'],
],
'OPS-12' => [
    ['f'=>'SOC2','r'=>'CC4.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.34','s'=>'strong'],['f'=>'CSF','r'=>'ID.RA-06','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'11.4','s'=>'partial'],['f'=>'CIS','r'=>'18.3','s'=>'exact'],
],

// ========================= INC =========================
'INC-01' => [
    ['f'=>'SOC2','r'=>'CC7.3','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.24','s'=>'exact'],['f'=>'CSF','r'=>'RS.MA-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.6.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'12.10','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.308(a)(6)(i)','s'=>'exact'],['f'=>'CIS','r'=>'17.1','s'=>'exact'],
],
'INC-02' => [
    ['f'=>'SOC2','r'=>'CC7.3','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.25','s'=>'exact'],['f'=>'CSF','r'=>'RS.MA-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.6.1','s'=>'strong'],['f'=>'CIS','r'=>'17.2','s'=>'exact'],
],
'INC-03' => [
    ['f'=>'SOC2','r'=>'CC7.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.25','s'=>'strong'],['f'=>'CSF','r'=>'DE.AE-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.2','s'=>'strong'],['f'=>'NIST800171','r'=>'3.6.2','s'=>'strong'],['f'=>'PCI_DSS','r'=>'10.6','s'=>'strong'],
    ['f'=>'CIS','r'=>'17.3','s'=>'exact'],
],
'INC-04' => [
    ['f'=>'SOC2','r'=>'CC7.3','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.26','s'=>'exact'],['f'=>'CSF','r'=>'RS.MI-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.6.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'12.10.3','s'=>'strong'],
    ['f'=>'CIS','r'=>'17.4','s'=>'exact'],
],
'INC-05' => [
    ['f'=>'SOC2','r'=>'CC7.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.28','s'=>'exact'],['f'=>'CSF','r'=>'RS.AN-03','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.2','s'=>'partial'],['f'=>'NIST800171','r'=>'3.6.2','s'=>'partial'],['f'=>'CIS','r'=>'17.6','s'=>'exact'],
],
'INC-06' => [
    ['f'=>'SOC2','r'=>'CC7.4','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.26','s'=>'strong'],['f'=>'CSF','r'=>'RS.CO-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.2','s'=>'strong'],['f'=>'NIST800171','r'=>'3.6.2','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.10.1','s'=>'strong'],
    ['f'=>'CIS','r'=>'17.1','s'=>'partial'],
],
'INC-07' => [
    ['f'=>'SOC2','r'=>'CC7.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.26','s'=>'strong'],['f'=>'CSF','r'=>'RS.CO-02','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.10.6','s'=>'exact'],['f'=>'HIPAA','r'=>'164.308(a)(6)(ii)','s'=>'exact'],
],
'INC-08' => [
    ['f'=>'SOC2','r'=>'CC7.5','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.27','s'=>'exact'],['f'=>'CSF','r'=>'RS.AN-08','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.3','s'=>'exact'],['f'=>'NIST800171','r'=>'3.6.3','s'=>'exact'],['f'=>'PCI_DSS','r'=>'12.10.7','s'=>'strong'],
    ['f'=>'CIS','r'=>'17.8','s'=>'exact'],
],
'INC-09' => [
    ['f'=>'SOC2','r'=>'CC7.3','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.24','s'=>'strong'],['f'=>'CSF','r'=>'RS.MA-03','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.6.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.10.2','s'=>'exact'],
    ['f'=>'CIS','r'=>'17.1','s'=>'strong'],
],
'INC-10' => [
    ['f'=>'SOC2','r'=>'CC7.3','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.24','s'=>'strong'],['f'=>'CSF','r'=>'RS.MA-04','s'=>'exact'],
    ['f'=>'CMMC','r'=>'IR.L2-3.6.3','s'=>'strong'],['f'=>'NIST800171','r'=>'3.6.3','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.10.4','s'=>'exact'],
    ['f'=>'CIS','r'=>'17.7','s'=>'exact'],
],

// ========================= SCM =========================
'SCM-01' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.19','s'=>'exact'],['f'=>'CSF','r'=>'GV.SC-03','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.4','s'=>'partial'],['f'=>'PCI_DSS','r'=>'12.8','s'=>'exact'],['f'=>'HIPAA','r'=>'164.308(b)(1)','s'=>'exact'],
    ['f'=>'CIS','r'=>'15.1','s'=>'exact'],
],
'SCM-02' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.20','s'=>'exact'],['f'=>'CSF','r'=>'GV.SC-06','s'=>'exact'],
    ['f'=>'PCI_DSS','r'=>'12.8.2','s'=>'exact'],['f'=>'HIPAA','r'=>'164.308(b)(4)','s'=>'exact'],['f'=>'CIS','r'=>'15.2','s'=>'exact'],
],
'SCM-03' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.22','s'=>'exact'],['f'=>'CSF','r'=>'GV.SC-07','s'=>'exact'],
    ['f'=>'PCI_DSS','r'=>'12.8.4','s'=>'exact'],['f'=>'CIS','r'=>'15.4','s'=>'exact'],
],
'SCM-04' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.21','s'=>'exact'],['f'=>'CSF','r'=>'GV.SC-04','s'=>'exact'],
    ['f'=>'CSF','r'=>'GV.SC-05','s'=>'strong'],['f'=>'CMMC','r'=>'SC.L2-3.13.1','s'=>'partial'],['f'=>'CIS','r'=>'15.3','s'=>'strong'],
],
'SCM-05' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.21','s'=>'strong'],['f'=>'CSF','r'=>'GV.SC-04','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.8.5','s'=>'strong'],['f'=>'CIS','r'=>'15.1','s'=>'partial'],
],
'SCM-06' => [
    ['f'=>'SOC2','r'=>'CC6.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.20','s'=>'strong'],['f'=>'CSF','r'=>'GV.SC-08','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AC.L2-3.1.1','s'=>'partial'],['f'=>'PCI_DSS','r'=>'12.8.3','s'=>'strong'],['f'=>'CIS','r'=>'15.5','s'=>'exact'],
],
'SCM-07' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.22','s'=>'strong'],['f'=>'CSF','r'=>'GV.SC-07','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.8.4','s'=>'strong'],
],
'SCM-08' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.21','s'=>'partial'],['f'=>'CSF','r'=>'GV.SC-04','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.8.5','s'=>'partial'],
],
'SCM-09' => [
    ['f'=>'SOC2','r'=>'CC7.4','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.20','s'=>'strong'],['f'=>'CSF','r'=>'GV.SC-06','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'12.8.2','s'=>'strong'],['f'=>'HIPAA','r'=>'164.308(b)(1)','s'=>'partial'],
],
'SCM-10' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.23','s'=>'exact'],['f'=>'CSF','r'=>'GV.SC-03','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.8.1','s'=>'strong'],['f'=>'CIS','r'=>'15.1','s'=>'strong'],
],

// ========================= PHY =========================
'PHY-01' => [
    ['f'=>'SOC2','r'=>'CC6.4','s'=>'exact'],['f'=>'ISO27001','r'=>'A.7.1','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-02','s'=>'strong'],
    ['f'=>'CMMC','r'=>'PE.L1-b.1.ix','s'=>'exact'],['f'=>'NIST800171','r'=>'3.10.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'9.1','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.310(a)(1)','s'=>'exact'],['f'=>'CIS','r'=>'1.1','s'=>'partial'],
],
'PHY-02' => [
    ['f'=>'SOC2','r'=>'CC6.4','s'=>'exact'],['f'=>'ISO27001','r'=>'A.7.5','s'=>'exact'],['f'=>'CSF','r'=>'PR.IR-02','s'=>'strong'],
    ['f'=>'CMMC','r'=>'PE.L2-3.10.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.10.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'9.1','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.310(a)(2)(ii)','s'=>'exact'],
],
'PHY-03' => [
    ['f'=>'SOC2','r'=>'A1.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.7.5','s'=>'strong'],['f'=>'CSF','r'=>'PR.IR-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'PE.L2-3.10.3','s'=>'exact'],['f'=>'NIST800171','r'=>'3.10.3','s'=>'exact'],['f'=>'PCI_DSS','r'=>'9.1','s'=>'partial'],
    ['f'=>'HIPAA','r'=>'164.310(a)(2)(ii)','s'=>'strong'],
],
'PHY-04' => [
    ['f'=>'SOC2','r'=>'CC6.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.7.2','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-02','s'=>'partial'],
    ['f'=>'CMMC','r'=>'PE.L2-3.10.3','s'=>'strong'],['f'=>'NIST800171','r'=>'3.10.3','s'=>'strong'],['f'=>'PCI_DSS','r'=>'9.3','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.310(a)(2)(iii)','s'=>'strong'],
],
'PHY-05' => [
    ['f'=>'SOC2','r'=>'CC6.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.7.4','s'=>'exact'],['f'=>'CSF','r'=>'DE.CM-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'PE.L2-3.10.2','s'=>'partial'],['f'=>'NIST800171','r'=>'3.10.2','s'=>'partial'],['f'=>'PCI_DSS','r'=>'9.1.1','s'=>'exact'],
],
'PHY-06' => [
    ['f'=>'SOC2','r'=>'CC6.5','s'=>'exact'],['f'=>'ISO27001','r'=>'A.7.14','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-10','s'=>'exact'],
    ['f'=>'CMMC','r'=>'MP.L2-3.8.3','s'=>'exact'],['f'=>'NIST800171','r'=>'3.8.3','s'=>'exact'],['f'=>'PCI_DSS','r'=>'9.4','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.310(d)(2)(i)','s'=>'exact'],['f'=>'CIS','r'=>'3.5','s'=>'strong'],
],
'PHY-07' => [
    ['f'=>'SOC2','r'=>'CC6.4','s'=>'partial'],['f'=>'ISO27001','r'=>'A.7.7','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'9.3','s'=>'partial'],['f'=>'CIS','r'=>'3.1','s'=>'partial'],
],
'PHY-08' => [
    ['f'=>'SOC2','r'=>'CC6.4','s'=>'partial'],['f'=>'ISO27001','r'=>'A.7.12','s'=>'exact'],['f'=>'CSF','r'=>'PR.IR-02','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'9.1','s'=>'partial'],
],

// ========================= HRS =========================
'HRS-01' => [
    ['f'=>'SOC2','r'=>'CC1.4','s'=>'exact'],['f'=>'ISO27001','r'=>'A.6.1','s'=>'exact'],['f'=>'CSF','r'=>'GV.RR-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'PS.L2-3.9.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.9.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'12.7','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.308(a)(3)(ii)(B)','s'=>'strong'],['f'=>'CIS','r'=>'14.1','s'=>'strong'],
],
'HRS-02' => [
    ['f'=>'SOC2','r'=>'CC1.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.6.3','s'=>'exact'],['f'=>'CSF','r'=>'PR.AT-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AT.L2-3.2.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.2.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'12.6','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.308(a)(5)(i)','s'=>'exact'],['f'=>'CIS','r'=>'14.1','s'=>'exact'],
],
'HRS-03' => [
    ['f'=>'SOC2','r'=>'CC1.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.6.3','s'=>'strong'],['f'=>'CSF','r'=>'PR.AT-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'AT.L2-3.2.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.2.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'12.6','s'=>'strong'],
    ['f'=>'CIS','r'=>'14.9','s'=>'exact'],
],
'HRS-04' => [
    ['f'=>'SOC2','r'=>'CC1.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.6.1','s'=>'strong'],['f'=>'CSF','r'=>'PR.AT-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'PS.L2-3.9.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.9.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.6','s'=>'partial'],
    ['f'=>'HIPAA','r'=>'164.308(a)(3)(ii)(A)','s'=>'strong'],
],
'HRS-05' => [
    ['f'=>'SOC2','r'=>'CC6.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.6.5','s'=>'exact'],['f'=>'CSF','r'=>'PR.AA-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'PS.L2-3.9.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.9.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'8.1','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.308(a)(3)(ii)(C)','s'=>'exact'],['f'=>'CIS','r'=>'5.1','s'=>'partial'],
],
'HRS-06' => [
    ['f'=>'SOC2','r'=>'CC1.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.10','s'=>'exact'],['f'=>'CSF','r'=>'GV.PO-01','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'12.3','s'=>'strong'],['f'=>'HIPAA','r'=>'164.310(b)','s'=>'partial'],['f'=>'CIS','r'=>'14.1','s'=>'partial'],
],
'HRS-07' => [
    ['f'=>'SOC2','r'=>'CC1.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.6.6','s'=>'exact'],['f'=>'CSF','r'=>'GV.PO-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'PS.L2-3.9.1','s'=>'partial'],['f'=>'PCI_DSS','r'=>'12.8.2','s'=>'partial'],['f'=>'HIPAA','r'=>'164.308(a)(4)(ii)(B)','s'=>'strong'],
],
'HRS-08' => [
    ['f'=>'SOC2','r'=>'CC1.5','s'=>'exact'],['f'=>'ISO27001','r'=>'A.6.4','s'=>'exact'],['f'=>'CSF','r'=>'GV.RR-04','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'12.6','s'=>'partial'],['f'=>'HIPAA','r'=>'164.308(a)(1)(ii)(C)','s'=>'strong'],
],
'HRS-09' => [
    ['f'=>'SOC2','r'=>'CC1.4','s'=>'partial'],['f'=>'ISO27001','r'=>'A.6.3','s'=>'partial'],['f'=>'CSF','r'=>'PR.AT-01','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'12.6','s'=>'partial'],['f'=>'CIS','r'=>'14.1','s'=>'partial'],
],
'HRS-10' => [
    ['f'=>'SOC2','r'=>'CC1.4','s'=>'strong'],['f'=>'ISO27001','r'=>'A.6.1','s'=>'strong'],['f'=>'CSF','r'=>'GV.RR-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'PS.L2-3.9.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.9.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.7','s'=>'strong'],
    ['f'=>'HIPAA','r'=>'164.308(a)(3)(ii)(A)','s'=>'strong'],
],

// ========================= BCP =========================
'BCP-01' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.29','s'=>'exact'],['f'=>'CSF','r'=>'RC.RP-01','s'=>'exact'],
    ['f'=>'PCI_DSS','r'=>'12.10','s'=>'partial'],['f'=>'HIPAA','r'=>'164.308(a)(7)(i)','s'=>'exact'],['f'=>'CIS','r'=>'17.1','s'=>'partial'],
],
'BCP-02' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.30','s'=>'exact'],['f'=>'CSF','r'=>'RC.RP-02','s'=>'exact'],
    ['f'=>'PCI_DSS','r'=>'12.10','s'=>'partial'],['f'=>'HIPAA','r'=>'164.308(a)(7)(ii)(B)','s'=>'exact'],
],
'BCP-03' => [
    ['f'=>'SOC2','r'=>'A1.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.29','s'=>'strong'],['f'=>'CSF','r'=>'ID.RA-09','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.308(a)(7)(ii)(E)','s'=>'exact'],
],
'BCP-04' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.30','s'=>'strong'],['f'=>'CSF','r'=>'RC.RP-04','s'=>'exact'],
    ['f'=>'PCI_DSS','r'=>'12.10','s'=>'partial'],['f'=>'HIPAA','r'=>'164.308(a)(7)(ii)(B)','s'=>'strong'],
],
'BCP-05' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.13','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-09','s'=>'exact'],
    ['f'=>'CMMC','r'=>'MP.L2-3.8.9','s'=>'exact'],['f'=>'NIST800171','r'=>'3.8.9','s'=>'exact'],['f'=>'PCI_DSS','r'=>'9.5','s'=>'partial'],
    ['f'=>'HIPAA','r'=>'164.308(a)(7)(ii)(A)','s'=>'exact'],['f'=>'CIS','r'=>'11.1','s'=>'exact'],
],
'BCP-06' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.29','s'=>'partial'],['f'=>'CSF','r'=>'RC.CO-01','s'=>'exact'],
    ['f'=>'CSF','r'=>'RC.CO-02','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.10.1','s'=>'partial'],
],
'BCP-07' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.30','s'=>'strong'],['f'=>'CSF','r'=>'RC.RP-03','s'=>'exact'],
    ['f'=>'HIPAA','r'=>'164.308(a)(7)(ii)(C)','s'=>'exact'],
],
'BCP-08' => [
    ['f'=>'SOC2','r'=>'A1.3','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.30','s'=>'exact'],['f'=>'CSF','r'=>'RC.RP-05','s'=>'exact'],
    ['f'=>'PCI_DSS','r'=>'12.10.2','s'=>'strong'],['f'=>'HIPAA','r'=>'164.308(a)(7)(ii)(D)','s'=>'exact'],
],
'BCP-09' => [
    ['f'=>'SOC2','r'=>'A1.2','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.29','s'=>'partial'],['f'=>'CSF','r'=>'RC.RP-01','s'=>'partial'],
    ['f'=>'HIPAA','r'=>'164.308(a)(7)(i)','s'=>'partial'],
],
'BCP-10' => [
    ['f'=>'SOC2','r'=>'CC9.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.21','s'=>'strong'],['f'=>'CSF','r'=>'GV.SC-04','s'=>'strong'],
    ['f'=>'CSF','r'=>'RC.RP-01','s'=>'partial'],['f'=>'PCI_DSS','r'=>'12.8.4','s'=>'partial'],
],

// ========================= CRY =========================
'CRY-01' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'strong'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.11','s'=>'exact'],['f'=>'NIST800171','r'=>'3.13.11','s'=>'exact'],['f'=>'PCI_DSS','r'=>'3.6','s'=>'exact'],
    ['f'=>'CIS','r'=>'3.6','s'=>'strong'],
],
'CRY-02' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'strong'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.11','s'=>'strong'],['f'=>'NIST800171','r'=>'3.13.11','s'=>'strong'],['f'=>'PCI_DSS','r'=>'3.6','s'=>'strong'],
],
'CRY-03' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'strong'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.10','s'=>'exact'],['f'=>'NIST800171','r'=>'3.13.10','s'=>'exact'],['f'=>'PCI_DSS','r'=>'3.6.3','s'=>'exact'],
    ['f'=>'CIS','r'=>'3.6','s'=>'partial'],
],
'CRY-04' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'strong'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.10','s'=>'strong'],['f'=>'NIST800171','r'=>'3.13.10','s'=>'strong'],['f'=>'PCI_DSS','r'=>'3.7','s'=>'exact'],
],
'CRY-05' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'partial'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'partial'],['f'=>'CSF','r'=>'PR.DS-02','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'3.6','s'=>'partial'],['f'=>'CIS','r'=>'3.10','s'=>'partial'],
],
'CRY-06' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'exact'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'strong'],['f'=>'CSF','r'=>'PR.DS-02','s'=>'strong'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.8','s'=>'strong'],['f'=>'NIST800171','r'=>'3.13.8','s'=>'strong'],['f'=>'PCI_DSS','r'=>'4.1','s'=>'exact'],
    ['f'=>'CIS','r'=>'3.10','s'=>'exact'],
],
'CRY-07' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'strong'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'exact'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'CMMC','r'=>'SC.L2-3.13.11','s'=>'strong'],['f'=>'NIST800171','r'=>'3.13.11','s'=>'strong'],['f'=>'PCI_DSS','r'=>'3.5','s'=>'strong'],
],
'CRY-08' => [
    ['f'=>'SOC2','r'=>'CC6.7','s'=>'partial'],['f'=>'ISO27001','r'=>'A.8.24','s'=>'partial'],['f'=>'CSF','r'=>'PR.DS-01','s'=>'partial'],
    ['f'=>'PCI_DSS','r'=>'3.6','s'=>'partial'],
],

// ========================= CMP =========================
'CMP-01' => [
    ['f'=>'SOC2','r'=>'CC3.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.31','s'=>'exact'],['f'=>'CSF','r'=>'GV.OC-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.1','s'=>'strong'],['f'=>'NIST800171','r'=>'3.12.1','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.1','s'=>'strong'],
    ['f'=>'SOX','r'=>'ITGC-SM-01','s'=>'exact'],['f'=>'HIPAA','r'=>'164.316(a)','s'=>'strong'],
],
'CMP-02' => [
    ['f'=>'SOC2','r'=>'CC4.1','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.35','s'=>'exact'],['f'=>'CSF','r'=>'ID.IM-02','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.1','s'=>'exact'],['f'=>'NIST800171','r'=>'3.12.1','s'=>'exact'],['f'=>'PCI_DSS','r'=>'12.4','s'=>'strong'],
    ['f'=>'SOX','r'=>'ITGC-SM-03','s'=>'exact'],['f'=>'HIPAA','r'=>'164.308(a)(8)','s'=>'exact'],
],
'CMP-03' => [
    ['f'=>'SOC2','r'=>'CC4.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.35','s'=>'strong'],['f'=>'CSF','r'=>'ID.IM-02','s'=>'strong'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.3','s'=>'exact'],['f'=>'NIST800171','r'=>'3.12.3','s'=>'exact'],['f'=>'PCI_DSS','r'=>'11.1','s'=>'strong'],
    ['f'=>'SOX','r'=>'ITGC-SM-03','s'=>'strong'],
],
'CMP-04' => [
    ['f'=>'SOC2','r'=>'CC4.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.36','s'=>'exact'],['f'=>'CSF','r'=>'ID.IM-01','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.4','s'=>'strong'],['f'=>'NIST800171','r'=>'3.12.4','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.4','s'=>'strong'],
    ['f'=>'SOX','r'=>'ITGC-SM-03','s'=>'strong'],
],
'CMP-05' => [
    ['f'=>'SOC2','r'=>'CC4.2','s'=>'exact'],['f'=>'ISO27001','r'=>'A.5.35','s'=>'exact'],['f'=>'CSF','r'=>'ID.IM-04','s'=>'exact'],
    ['f'=>'PCI_DSS','r'=>'12.4','s'=>'partial'],['f'=>'SOX','r'=>'ITGC-SM-03','s'=>'strong'],['f'=>'HIPAA','r'=>'164.308(a)(8)','s'=>'strong'],
],
'CMP-06' => [
    ['f'=>'SOC2','r'=>'CC4.2','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.36','s'=>'strong'],['f'=>'CSF','r'=>'ID.IM-03','s'=>'exact'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.2','s'=>'exact'],['f'=>'NIST800171','r'=>'3.12.2','s'=>'exact'],['f'=>'PCI_DSS','r'=>'12.4','s'=>'partial'],
],
'CMP-07' => [
    ['f'=>'SOC2','r'=>'CC3.1','s'=>'partial'],['f'=>'ISO27001','r'=>'A.5.31','s'=>'strong'],['f'=>'CSF','r'=>'GV.OC-02','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.1','s'=>'partial'],['f'=>'SOX','r'=>'ITGC-SM-02','s'=>'strong'],
],
'CMP-08' => [
    ['f'=>'SOC2','r'=>'P1.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.34','s'=>'exact'],['f'=>'CSF','r'=>'GV.OC-02','s'=>'strong'],
    ['f'=>'PCI_DSS','r'=>'12.1','s'=>'partial'],['f'=>'HIPAA','r'=>'164.530(c)','s'=>'exact'],
],
'CMP-09' => [
    ['f'=>'SOC2','r'=>'CC3.1','s'=>'strong'],['f'=>'ISO27001','r'=>'A.5.31','s'=>'strong'],['f'=>'CSF','r'=>'GV.OC-02','s'=>'strong'],
    ['f'=>'CMMC','r'=>'CA.L2-3.12.4','s'=>'strong'],['f'=>'NIST800171','r'=>'3.12.4','s'=>'strong'],['f'=>'PCI_DSS','r'=>'12.1','s'=>'strong'],
    ['f'=>'SOX','r'=>'ITGC-SM-01','s'=>'strong'],['f'=>'HIPAA','r'=>'164.316(a)','s'=>'strong'],
],

]; // End of mappings
