# مواصفة منصة النماذج الضريبية الأمريكية والألمانية

## حالة الوثيقة

هذه مواصفة تقنية لبناء طبقة استخراج وتجهيز ومراجعة للنماذج الضريبية داخل نظام المحاسبة. الناتج الآلي هو **حزمة مراجعة** تشمل الأرقام، مصادرها، قواعد التحقق، وملفات XML/JSON/PDF عند توفر مخطط رسمي معتمد. لا تعتبر الحزمة إقرارًا مقدمًا ولا بديلًا عن CPA أو Steuerberater أو التوقيع/التفويض القانوني.

يجب أن تكون كل قاعدة مرتبطة بـ `jurisdiction` و`tax_year` و`version` و`effective_from` و`effective_to` ومصدر رسمي. لا يجوز تثبيت معدل أو حد أو رقم سطر داخل Controller أو Blade.

## 1. نطاق النماذج

### الولايات المتحدة

يُسجل كل نموذج كنسخة سنوية أو دورية مستقلة، مع فصل `federal` عن `state` و`local`:

- الأفراد والمالكون: Form 1040، الجداول المرتبطة، و1099 ذات الصلة.
- الشركات: Form 1120، Form 1120-S، Form 1065، Form 7004 للتمديد، وForms 1120-F عند انطباقها.
- الرواتب الفيدرالية: Forms 941، 941-X، 940، 944، 945، W-2، W-3، وبيانات الأجور والاقتطاعات.
- المعلومات والمدفوعات: 1099-NEC، 1099-MISC، 1099-INT، 1099-DIV، 1099-K، 1099-DA أو أي سلسلة يضيفها IRS، و1042-S عند المدفوعات إلى أشخاص أجانب.
- النماذج الاختيارية حسب النشاط: 1041، 720، 2290، 8849، 990، والنماذج القطاعية الأخرى.
- حزمة الولاية لكل ولاية يجب أن تكون Plugin منفصلًا يصف ضريبة الدخل أو Franchise، وضريبة المبيعات والاستخدام، واقتطاع الرواتب، والبطالة، والإقرارات المحلية أو المركبة. لا يصح إنشاء «نموذج ولائي عام» لأن الحقول ومواعيد التقديم وXML تختلف حسب الولاية.

تدعم IRS نماذج أعمال كثيرة إلكترونيًا، ومنها 1120 و1120-F و1120-S، ونماذج الرواتب، ومعلومات 1099 عبر FIRE، وتقديم الشراكات عبر MeF. يجب اعتماد المصدر الرسمي ومخطط السنة قبل توليد ملف قابل للإرسال. [1] [2]

### ألمانيا

- دخل الأشخاص: Einkommensteuererklärung، ومن ضمنها ESt 1A، مع جداول الدخل والعمل والنشاط التجاري بحسب الحالة.
- الشراكات: ESt 1B أو نماذج Feststellung ذات الصلة عند وجود Feststellung جماعي.
- الشركات: Körperschaftsteuererklärung، Gewerbesteuererklärung، والنماذج الملحقة.
- القيمة المضافة: Umsatzsteuer-Voranmeldung، Umsatzsteuer-Jahreserklärung، Zusammenfassende Meldung، وطلبات Dauerfristverlängerung عند انطباقها.
- الرواتب: Lohnsteuer-Anmeldung، Lohnsteuerbescheinigung، وبيانات ELStAM التي لا ينبغي حسابها من نظام محاسبي عام دون تكامل متخصص.
- نماذج أخرى حسب النشاط: Kapitalertragsteuer، Grundsteuer، Bauabzugsteuer، وإفصاحات المعاملات العابرة للحدود.

تعرض ELSTER هذه العائلات رسميًا ضمن أقسام Einkommensteuer وKörperschaftsteuer وLohnsteuer وUmsatzsteuer وغيرها، ولذلك يجب أن يكون سجل النماذج قابلًا للإضافة لا قائمة ثابتة داخل التطبيق. [3]

## 2. المعمارية المقترحة

تتكون الطبقة من ست وحدات:

1. `TaxProfile`: تعريف الشركة، الكيان، البلد، الولاية، التسجيلات، الفترات، العملة، وطريقة التقديم.
2. `TaxRulePack`: معدلات وحدود وقواعد سنة محددة مع مصدر ومراجعة قانونية.
3. `LedgerMapper`: تحويل القيود والفواتير والرواتب والمخزون إلى أحداث ضريبية موحدة.
4. `TaxFormDefinition`: تعريف النموذج وحقوله وقواعده ومخطط XML ونسخة PDF.
5. `TaxReturnRun`: لقطة immutable لمصدر البيانات وحالة الحساب والمراجعة والإخراج.
6. `SubmissionAdapter`: موصل IRS/SSA/الولاية أو ELSTER، ويُفعل فقط بعد اعتماد بيانات الاعتماد والمخطط.

لا يُسمح للنموذج بقراءة جداول التطبيق مباشرة. يقرأ من `TaxFacts` الموحّدة، ما يجعل تغيير النموذج أو البلد منفصلًا عن دفتر الأستاذ.

## 3. نموذج البيانات الأساسي

### TaxProfile

```json
{
  "company_id": 123,
  "jurisdiction": "US-CA",
  "entity_type": "corporation",
  "tax_year": 2026,
  "ein_or_steuernummer_ref": "vault://tax-identifiers/123/ein",
  "filing_frequency": "quarterly",
  "accounting_basis": "accrual",
  "currency": "USD",
  "reviewer_required": true,
  "rule_pack_version": "us-ca-2026.1"
}
```

### TaxFormDefinition

```json
{
  "code": "US-FORM-941",
  "jurisdiction": "US-FED",
  "tax_year": 2026,
  "period_type": "quarter",
  "version": "2026.1",
  "delivery": ["review_json", "review_pdf", "efile_xml"],
  "schema_uri": "official-registry://irs/mef/94x/2026.1",
  "business_rules_uri": "official-registry://irs/mef/94x/2026.1/rules",
  "requires_paid_preparer_review": true,
  "status": "draft_until_legal_approval"
}
```

### TaxFact

كل حقيقة ضريبية تحمل الأصل والتفسير:

```json
{
  "key": "wages.social_security.wages",
  "value": "125000.00",
  "currency": "USD",
  "period_start": "2026-01-01",
  "period_end": "2026-03-31",
  "company_id": 123,
  "source": [
    {"type": "journal_line", "id": 8812, "account_code": "6100", "amount": "125000.00"}
  ],
  "rule_pack": "us-fed-2026.1",
  "calculation_trace_hash": "sha256:..."
}
```

### FormField

لكل حقل:

- `field_key`, `display_label`, `form_line`, `data_type`, `scale`, `sign_policy`.
- `source_facts` و`account_filters` و`event_filters`.
- `formula` بصيغة محدودة قابلة للتدقيق، لا PHP حر داخل قاعدة البيانات.
- `rounding_policy` و`zero_policy` و`required_if`.
- `validation_rules` و`legal_notes` و`source_reference`.
- `review_status` و`last_approved_by` و`approved_at`.

## 4. خريطة دفتر الحسابات إلى الحقائق الضريبية

تُضاف طبقة `tax_tags` للحسابات وبنود المستندات بدل الاعتماد على أرقام حسابات ثابتة:

- إيراد خاضع أو معفى أو خارج النطاق.
- مشتريات قابلة أو غير قابلة لخصم VAT.
- أجور Social Security وMedicare وFederal Withholding وFUTA وSUTA.
- مخزون وتكلفة مبيعات وأصول واستهلاك.
- توزيعات، فوائد، إيجارات، خدمات لمقاولين، ومدفوعات لأشخاص أجانب.
- ولاية المصدر وولاية العميل وبلد المورد وحالة الشهادة الضريبية.

مثال الربط:

```yaml
account_code: "4101"
tax_tags:
  - us_income.gross_receipts
  - us_sales_tax.taxable_sales
  - de_vat.output_standard
excluded_from:
  - us_payroll.wages
```

عند وجود أكثر من استعمال للحساب، يكون الحسم من سطر المستند أو Tax Code، وليس من الحساب العام وحده. كل رقم في المخرج يجب أن يعرض `drill_down` للقيود والمستندات والقاعدة التي أنتجته.

## 5. الحساب والتقريب

- تُخزن مبالغ دفتر الأستاذ باستخدام Decimal، وليس binary float.
- يفضل تخزين الدقة الداخلية 4 إلى 6 منازل عند حساب النسب، ثم تقريب مستوى الحقل حسب مواصفة النموذج.
- يُطبق التقريب في آخر خطوة من كل حقل، مع سياسة صريحة: `half_up` أو سياسة المصدر الرسمي.
- تحفظ القيم الخام، وقيمة الحقل بعد التقريب، وفارق التقريب.
- عند توزيع ضريبة فاتورة على عدة أسطر، يوزع الفرق المتبقي على أكبر سطر خاضع للضريبة مع تسجيل القاعدة.
- لا يُسمح بتعديل قيمة حقل يدويًا دون `override_reason` واسم المراجع.
- يجب أن يساوي مجموع السطور الناتج الكلي بعد سياسة التقريب، وإلا تظهر حالة `out_of_balance` ولا يُصدر ملف تقديم.

## 6. القواعد والمعدلات

كل معدل أو حد يكون سجلًا versioned:

```json
{
  "rule_key": "us.fica.social_security.employee_rate",
  "jurisdiction": "US-FED",
  "tax_year": 2026,
  "effective_from": "2026-01-01",
  "effective_to": "2026-12-31",
  "value": "0.062",
  "unit": "rate",
  "source_url": "https://www.irs.gov/",
  "legal_review": {"status": "pending", "reviewer": null}
}
```

لا يثبت النظام أرقامًا من هذه المواصفة على أنها معدلات قانونية نهائية. تُستورد القواعد من حزم رسمية أو إدخال موثق، ثم تمر عبر مراجعة قانونية للشركة والسنة. يجب دعم tax holidays، حدود nexus، إعفاءات العملاء، reverse charge، Kleinunternehmer، وحالات المعاملات عبر الحدود كقواعد شروط لا كحسابات خاصة داخل الواجهة.

## 7. حالات التقديم

حالة `TaxReturnRun` المقترحة:

`draft → calculated → validation_failed | ready_for_review → approved → signed → transmitted → accepted | rejected → amended`

تدعم النسخة:

- ربع سنوي وسنوي وشهري حسب النموذج.
- Original وAmended وCorrected وSuperseding، مع ربط كل نسخة بالنسخة السابقة.
- شركات ذات أكثر من فرع أو ولاية.
- consolidated/group returns عبر كيان رئيسي وكيانات تابعة وصلاحيات منفصلة.
- معالجة الاقتطاع عند المصدر والمعاهدات الضريبية كـ Tax Facts موثقة بمستند الإقامة والنماذج المناسبة، لا كإعفاء تلقائي.
- فصل فترة الرواتب عن فترة الإقرار عند اختلاف تاريخ الدفع عن فترة العمل.

## 8. تنسيقات الإخراج والتكامل

### IRS

يجب فصل:

- `review.json`: حزمة بشرية قابلة للمقارنة، مع provenance لكل حقل.
- `review.pdf`: عرض ومرفقات، بعلامة واضحة `NOT FILED` حتى يعتمدها المراجع.
- `efile.xml`: ملف مطابق لمخطط IRS للسنة ومرفق بقواعد Business Rules.
- `transmission.json`: رقم تشغيل، hash، وقت الإرسال، provider، status، ورسائل الرفض.

MeF يعتمد XML ومخططات وقواعد رفض منشورة للمطورين، وتوزع بعض المواد عبر Registered User Portal وe-services mailbox؛ لذلك لا يجوز شحن XML مولد من تخمين أو من PDF فقط. [2]

تُفصل موصلات IRS إلى Provider Adapter لأن الوصول والتسجيل والاعتماد يختلفان. Form 1099/W-2 لهما مسارات معلومات مختلفة مثل FIRE وSSA/IRIS، فلا يعاملان كـ MeF واحد. [1] [4]

### ELSTER

تُبنى طبقة `ElsterEricAdapter` حول ERiC، مع عدم وضع مكتبة ERiC أو مفاتيحها داخل المستودع. ELSTER يطلب تسجيل الشركة كمصنع/مطوّر، ثم منح الوصول إلى منطقة المطور وتنزيل ERiC والتقدم للحصول على Hersteller-ID. [5]

مخرجات ELSTER لا تُعتبر قابلة للإرسال قبل:

- توافق نسخة ERiC مع النموذج والفترة.
- تحقق XSD وقواعد ERiC.
- اختبار رفض وقبول في بيئة التطوير عند توفرها.
- اعتماد الهوية والتفويض القانوني.

## 9. واجهات داخل التطبيق

```text
GET    /tax/forms
POST   /tax/runs
GET    /tax/runs/{run}
POST   /tax/runs/{run}/calculate
POST   /tax/runs/{run}/validate
GET    /tax/runs/{run}/review
POST   /tax/runs/{run}/approve
POST   /tax/runs/{run}/export/json
POST   /tax/runs/{run}/export/pdf
POST   /tax/runs/{run}/export/xml
POST   /tax/runs/{run}/submit
POST   /tax/runs/{run}/amend
GET    /tax/runs/{run}/audit
```

كل عملية طويلة تُنفذ Job idempotent. الـ webhook يحدّث حالة الإرسال فقط بعد التحقق من التوقيع، ويدعم retry مع backoff وdead-letter queue. لا تُعاد عملية إرسال ناجحة بسبب timeout دون فحص `idempotency_key` وحالة المزود.

## 10. التحقق والاختبارات

### قواعد أساسية

- الفترة صحيحة ولا تتداخل مع نسخة معتمدة أخرى.
- الحسابات متوازنة: إجمالي المدين = إجمالي الدائن.
- مجموع الأجور والاقتطاعات يطابق دفتر الرواتب والقيود.
- لا يوجد حقل إلزامي فارغ بعد تطبيق `required_if`.
- المعرفات مثل EIN وSSN وUSt-IdNr وSteuernummer تتحقق من الصيغة دون تخزينها في السجلات المكشوفة.
- الولاية والبلد والعملة متوافقة مع Tax Profile.
- VAT output وinput وreverse charge وexemptions متوافقة مع Tax Codes والمستندات.
- XML يطابق XSD، ثم تُطبق business rules، ثم تُقارن النتائج مع JSON.
- أي فارق rounding يظهر كتقرير لا كتعديل صامت.

### حالات قبول إلزامية

1. شركة أمريكية فردية: دخل، مصروفات، 1040 schedule facts، و1099.
2. Corporation: 1120، ضريبة مبيعات ولاية، وpayroll 941/940.
3. Partnership: 1065 وتوزيع الشركاء.
4. ألمانيا: Umsatzsteuer-Voranmeldung مع 19% و7% وإعفاء وReverse Charge.
5. ألمانيا: Lohnsteuer-Anmeldung وLohnsteuerbescheinigung من مصدر رواتب معتمد.
6. عملية عبر الحدود مع USt-IdNr صحيحة وأخرى فاشلة.
7. تعديل بعد اعتماد الإقرار مع أثر كامل للنسخة السابقة.
8. تغيير قاعدة أو معدل في منتصف فترة مع تقسيم زمني.
9. تقريب 0.005، refunds، credit notes، negative lines، foreign exchange.
10. رفض XML، إعادة المحاولة، webhook مكرر، وانقطاع الاتصال.

## 11. الأمن والتدقيق

- أسرار EIN وSSN وUSt-IdNr وشهادات التوقيع في Vault أو Secret Manager، مع إخفائها في logs وPDF غير المصرح.
- تشفير أثناء النقل والتخزين، ومفاتيح منفصلة لكل بيئة.
- صلاحيات RBAC: preparer، reviewer، tax-admin، submitter، auditor، مع فصل من يحسب عمن يعتمد ومن يرسل.
- Audit Event لكل قراءة حساسة وتغيير قاعدة وتعديل يدوي وتصدير وتوقيع وإرسال ورفض.
- hash للمخرجات والمدخلات، ونسخ immutable من TaxReturnRun بعد الاعتماد.
- توقيع PDF/XML فقط عبر خدمة مفاتيح معتمدة، مع تخزين certificate fingerprint ووقت التوقيع. التوقيع الداخلي لا يساوي توقيعًا قانونيًا لدى IRS أو ELSTER.
- طلبات الحذف تخضع لسياسة الاحتفاظ القانوني ولا تحذف TaxReturnRun المعتمد؛ تستخدم legal hold عند التدقيق.

## 12. تحديثات وامتثال

ينشأ `TaxRuleRelease` لكل تحديث:

1. رصد نشر رسمي أو تحديث مخطط.
2. حفظ المصدر والنسخة وhash للملف.
3. تحويل التغيير إلى Rule Pack جديد دون تعديل القديم.
4. تشغيل regression وgolden files.
5. مراجعة CPA/Steuerberater لكل jurisdiction متأثر.
6. اعتماد release وتحديد تاريخ السريان.
7. ترحيل الشركات المتأثرة إلى مراجعة بشرية.
8. توثيق changelog والحقول المتغيرة وحالات الرفض الجديدة.

بالنسبة لـ ELSTER، يجب متابعة نشرة ELSTER وتحديثات ERiC؛ وبالنسبة لـ IRS يجب متابعة MeF schemas وbusiness rules وknown issues لكل سنة. [2] [5]

## 13. خطة التنفيذ في المشروع الحالي

### P0: البنية والحوكمة

- جداول: `tax_profiles`, `tax_rule_packs`, `tax_rules`, `tax_form_definitions`, `tax_form_fields`, `tax_facts`, `tax_return_runs`, `tax_return_values`, `tax_validations`, `tax_submissions`, `tax_audit_events`.
- خدمات Laravel: `TaxFactBuilder`, `TaxRuleEngine`, `TaxFormRenderer`, `TaxValidationService`, `TaxAuditService`.
- واجهة مراجعة تعرض كل رقم مع مصدره.
- JSON schema داخلي مستقل عن XML الرسمي.
- عدم تفعيل إرسال حقيقي.

### P1: أمريكا

- البدء بـ 941 و940 وW-2/1099 review exports، ثم 1120/1120-S/1065 بحسب نوع الشركة.
- إضافة state plugin واحد فقط بعد اختيار أول ولاية مستهدفة.
- تكامل مزود e-file مع sandbox قبل الإنتاج.

### P2: ألمانيا

- UStVA وUSt-Jahreserklärung وZusammenfassende Meldung.
- Lohnsteuer-Anmeldung وLohnsteuerbescheinigung فقط مع مصدر رواتب واضح.
- ERiC sandbox/اختبار ثم الإنتاج بعد تسجيل المطور واعتماد Steuerberater.

### P3: التوسع

- نماذج 1040/جداول الأفراد عند تحديد علاقة الشركة بالمالكين.
- جميع الولايات المطلوبة كحزم مستقلة.
- KSt وGewSt وESt المتقدمة، والتقديم الفعلي بعد اعتماد قانوني.

## 14. معايير القبول

يُعتبر الإصدار جاهزًا للمراجعة عندما:

- ينتج كل نموذج مدعوم JSON/PDF مع provenance لكل حقل.
- يمر XML على XSD وbusiness rules الرسمية المتاحة لنفس السنة.
- تطابق المجاميع مع دفتر الحسابات والـ Tax Facts ضمن سياسة التقريب.
- توجد اختبارات golden لكل نموذج وسنة مدعومة.
- توجد صلاحيات وفصل اعتماد وتدقيق غير قابل للتعديل.
- يمكن إعادة بناء نفس المخرجات من نفس snapshot وrule-pack.
- يمكن تفسير أي رقم من سطر النموذج إلى قيد ومصدر وقاعدة.
- يرفض النظام الإرسال عند وجود validation errors أو غياب مراجعة الشركة.
- يعتمد CPA/Steuerberater قائمة الحقول والقواعد والنماذج قبل تفعيل الإرسال القانوني.

## القرارات المطلوبة قبل كتابة الكود

لا يمكن تنفيذ «مجموعة كاملة» نهائية دون هذه القيم:

1. نوع الكيانات التي يدعمها المنتج في أمريكا وألمانيا.
2. السنة الأولى المستهدفة، وهل هي 2026 أم سنة لاحقة.
3. الولايات الأمريكية الأولى، وهل المطلوب income tax أم sales/payroll أم كلها.
4. هل الشركة تقدم خدمة استخراج ومراجعة فقط أم تريد e-file فعليًا.
5. مزود الرواتب ومزود e-file المرغوبان، إن وُجدا.
6. هل يوجد CPA أمريكي وSteuerberater ألماني لاعتماد القواعد.
7. هل ستُحفظ بيانات أوروبا داخل الاتحاد الأوروبي، وأي منطقة استضافة معتمدة.

## المصادر الرسمية

[1]: https://www.irs.gov/businesses/small-businesses-self-employed/filing-and-paying-your-business-taxes "IRS Filing and paying your business taxes"
[2]: https://www.irs.gov/e-file-providers/modernized-e-file-mef-schemas-and-business-rules "IRS Modernized e-File schemas and business rules"
[3]: https://www.elster.de/eportal/formulare-leistungen/alleformulare "ELSTER All forms"
[4]: https://www.irs.gov/e-file-providers/electronic-filing-options-for-business-and-self-employed-taxpayers "IRS electronic filing options for business and self-employed taxpayers"
[5]: https://www.elster.de/eportal/infoseite/entwickler "ELSTER developer information and ERiC registration"
