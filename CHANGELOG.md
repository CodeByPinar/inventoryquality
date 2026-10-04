# Değişiklik Günlüğü

Tüm önemli değişiklikler bu dosyada tutulur. Sürümleme [SemVer](https://semver.org/lang/tr/) tabanlıdır.

## [0.2.1] — 2026-10-04 — Ekran düzeltmeleri (gerçek kurulumdaki demo ekran görüntülerinden)

### Düzeltildi
- **Bulgular ve Kurallar listeleri** yalnız kimlik + birim sütunlarıyla açılıyordu (GLPI yeni tipte varsayılan sütun
  tanımlamaz): kurulum / güncelleme artık varsayılan sütunları ekler (varlık, kural kodu, kural, durum, önem, sorumlu
  grup, hedef tarih, son kontrol · kod, şablon, varlık tipi, etkin, askı nedeni, son değişiklik). Yöneticinin
  düzenlediği sütunlara dokunulmaz.
- **Varlık sekmesi:** teyit kuralının mevcut durumu iç anahtarla (`attest:…`) görünüyordu → okunur etiket; tarihler
  GLPI biçiminde; sonuçlar rozetle; teyit düğmesi dar ekranda taşmıyor.
- **Düzeltme sayfası:** onaylayan bulunamadığı için "İnceleme gerekli" olan öneride onay tablosu yanlışlıkla
  "bu kuralda onay adımı yok" diyordu → "uygun onaylayan bulunamadı; yeniden atayın ya da iptal edin".
- Kural kodları ve varlık adları listelerde satır kırmıyor.

## [0.2.0] — 2026-10-04 — İlk sürümün tamamlanması (tasarım aşama 3)

### Eklendi
- **Düzeltme önerisi ve onay akışı:** eklenti içinden öneri (izinli alan, veri tipine uygun değer, zorunlu gerekçe,
  isteğe bağlı kanıt eki); kurala bağlı onay politikası (onaysız / 1 adım / 2 sıralı adım; "biri yeterli" /
  "belirlenmiş herkes"; kendi talebini onaylama varsayılan kapalı); ret (gerekçe zorunlu), iptal, yetkili yeniden atama;
  onaylayan pasifleşirse "İnceleme gerekli".
- **Güvenli uygulama:** tek seferlik sahiplenme, anlık görüntü karşılaştırması, satır kilidi, CONFLICT, GLPI nesne
  güncellemesi + tarihçe + denetim kaydı tek işlemde (hata → geri alma), yeniden deneme, uygulama sonrası yeniden kontrol.
- **Süreli istisnalar** (gerekçe, onaylayan, bitiş, telafi edici işlem) ve saatlik süre dolumu görevi `iqexpire`.
- **DQ-07 Güncellik** (ajanın son bağlantısı / son envanter tarihi; isteğe bağlı yalnız otomatik envanter) ve
  **DQ-08 İş sahibi teyidi** (alan seçimi, periyot; teyit kaydı: kim, hangi alanlar, hangi değerler, ne zaman).
- **Güvenli CSV dışa aktarma** ("Rapor dışa aktar" yetkisi, birim kısıtlı, formül hücreleri korumalı).
- Ekranlar: Düzeltmeler, Düzeltme / Onay, İstisnalar; bulgu detayında öneri / istisna / teyit; varlık sekmesinde teyit;
  kural formunda onay politikası; Genel Bakış'ta düzeltme ve istisna göstergeleri.
- Destek kaydında düzeltme / onay / ret olayları takip notu olarak.
- 4 yeni tablo: corrections, approvals, exceptions, attestations.

### Değişti
- Kurulum / güncelleme GLPI'nin derlenmiş Twig şablon önbelleğini temizler (GLPI üretim modunda eklenti güncellemesinden
  sonra eski ekranları göstermeye devam ediyordu).

## [0.1.0] — 2026-10-04 — İlk kullanılabilir ürün

Tasarım: "GLPI 11 Envanter Veri Kalitesi ve Düzeltme Takibi" (Taslak v1.0, 04 Ekim 2026), 32. madde aşama 1–2.

### Teknik doğrulama (aşama 1)
- Hedef sürüm matrisi: GLPI 11.0.8 · PHP 8.3.6 · MariaDB 10.11.14 (Fields kurulu değil; çekirdek kapsam).
- Computer alan kataloğu hedef sürümde keşfedildi (23 alan: çekirdek kolonlar, gruplar / teknik gruplar, türetilmiş
  "sorumlu", ajanın son bağlantısı).
- Destek kaydı entegrasyonu denendi: varlık bağlantısı, grup ataması, takip notu, çözüm (Çözüldü durumu).
- Yetki ve kurum birimi kısıtı, otomatik görev kaydı ve GLPI'nin gerçek cron çalıştırıcısı doğrulandı.

### Eklendi
- Kural kataloğu DQ-01…DQ-06 (zorunlu alan, sorumlu, pasif kullanıcı, değer kümesi, koşullu zorunluluk, referans
  bütünlüğü); kapsam (birim, alt birim, varlık tipi, durum), önem, ağırlık, süre, sorumlu ve destek kaydı politikası.
- Kural önizleme (yan etkisiz), sürümleme, etkinleştirme / pasifleştirme, şema değişikliğinde askıya alma.
- Saf kural değerlendirici (PASS / FAIL / UNKNOWN / NOT_APPLICABLE; veri tipine göre boşluk kuralı).
- Periyodik tam tarama (partili, zaman bütçeli, devam ettirilebilir, kilitli) ve olay kuyruğu (yalnız değişen kayıt).
- Bulgu yaşam döngüsü: kararlı anahtar, tekilleştirme (benzersizlik kısıtı), dönemler, tekrar açılma, kapsam dışı.
- Sorumlu atama (kural → varlık teknik grubu / sorumlusu → birim veri kalitesi grubu → Atama bekliyor).
- Destek kaydı köprüsü: varlık + sorumlu başına tek kayıt, takip notları, doğrulanmış çözüm, erken kapanma politikası.
- Ağırlıklı kalite puanı + değerlendirme kapsamı; Genel Bakış, Bulgular, Bulgu Detayı, Kurallar, İşler, Ayarlar
  ekranları ve varlıkta "Veri Kalitesi" sekmesi.
- Profil yetkileri (10 ayrı hak), denetim izi (kullanıcı ve servis kimliği ayrı), kurum birimi ayarları (devralma).
