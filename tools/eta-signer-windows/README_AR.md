# Windows ETA Signing Server

هذه خدمة توقيع محلية مخصصة لربط نظام:

- `work.areagles.com`

مع جهاز `Windows` دائم عليه:

- `Egypt Trust token`
- تعريفات الشهادة / CSP

## الهدف

النظام الحالي يرسل إلى خدمة التوقيع:

- `POST /sign`

ويرسل:

```json
{
  "mode": "signing_server",
  "hash": "...",
  "document": { "...": "..." }
}
```

والخدمة يجب أن ترجع:

```json
{
  "ok": true,
  "document": { "...signed eta document..." },
  "signature": "base64..."
}
```

## مهم

هذه النسخة `scaffold` جاهزة للتركيب والتشغيل، لكنها ليست اعتماد ETA نهائيًا وحدها.

المتبقي قبل الإنتاج:

1. التأكد من أن شهادة `Egypt Trust` تظهر داخل:
   - `CurrentUser\\My`
2. وضع `CertificateThumbprint`
3. اختبار التوقيع الحقيقي على نفس جهاز Windows
4. مراجعة أن `canonicalization/signature envelope` مطابقان لمتطلبات ETA النهائية

## التثبيت على Windows

1. ثبّت:
   - `.NET 8 Runtime/SDK`
   - `Egypt Trust middleware`
   - التوكن نفسه

2. انسخ هذا المجلد إلى الجهاز.

3. عدّل:
   - `appsettings.json`

القيم المطلوبة:

- `ApiKey`
  - مفتاح داخلي بين النظام وخدمة التوقيع
- `BindUrl`
  - مثلًا: `http://0.0.0.0:8080`
- `CertificateThumbprint`
  - بصمة شهادة Egypt Trust
- `UseMockSigner`
  - `false` في التشغيل الحقيقي

## التشغيل

```powershell
dotnet run --project .\ArabEagles.EtaSigner.csproj
```

## اختبار محلي

```powershell
Invoke-RestMethod `
  -Method GET `
  -Uri http://127.0.0.1:8080/health
```

## ربطه بالنظام

داخل:

- `/Users/areagles/Desktop/sas/master_data.php`

على بيئة `work` ضع:

- `Signing Mode = Dedicated Signing Server`
- `Signing Service URL = http://WINDOWS-IP:8080`
- `Signing API Key = نفس قيمة ApiKey في appsettings.json`

## التوصية الشبكية

لا تفتح الخدمة مباشرة على الإنترنت.

الأفضل:

1. تثبيت `Tailscale` على:
   - جهاز Windows
   - والخادم الذي يشغّل `work`
2. استخدام عنوان Tailscale الداخلي فقط

مثال:

- `http://100.x.x.x:8080`

## الوضع الحالي في النظام

إذا لم تكن خدمة التوقيع مضبوطة:

- الفواتير الضريبية تحفظ في `ETA Outbox`
- كمسودات محلية بانتظار التوقيع
- ولا تُرسل إلى ETA حتى يتوفر signer
