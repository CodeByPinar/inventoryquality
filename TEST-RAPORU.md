# Test Raporu — inventoryquality 0.2.0

**Tarih:** 2026-10-04 · **Sonuç:** 185 / 185 kontrol geçti (motor 55, ekran 38, otomatik görev 6, 3. aşama motor 60,
3. aşama ekran 26). 0.1.0 → 0.2.0 güncellemesi (yeni tablolar, yeni görev) ayrıca denendi.

## 0.2.0 ile kapanan kabul testleri

| No | Senaryo | Sonuç | Kanıt |
| --- | --- | --- | --- |
| T06 | Onay tamamlanmadı / ret | ✓ | Onay beklerken hedef alan değişmedi; ret (gerekçe zorunlu) → envanter değişmedi, bulgu açık; kendi talebini onaylama engellendi |
| T07 | Onay sırasında hedef alan değişti | ✓ | CONFLICT; başkasının yazdığı değer ezilmedi; ilgisiz alan değişikliği çakışma üretmedi |
| T08 | Düzeltme başarılı / tekrar işleme | ✓ | Tek yazım + tek denetim kaydı; bulgu "Doğrulama bekliyor", yeniden kontrol PASS → Çözüldü; yeniden işleme ikinci kez uygulamadı |
| T09 | Yazma veya denetim adımı hatası | ✓ | Yapay hata → alan, GLPI tarihçesi ve denetim kaydı birlikte geri alındı; "Yeniden dene" bir kez uyguladı |
| T11 | İstisna süresi doldu | ✓ | FAIL → Açık, PASS → Çözüldü (istisna sona erdi), UNKNOWN → İnceleme gerekli; puan istisnayla değişmedi |

Ek: iki sıralı adım + "belirlenmiş herkes" (2. adım 1. adım bitince açıldı), onaylayan pasifleşince İnceleme gerekli ve
yeniden atama, onaylayan yalnız öneren olduğunda İnceleme gerekli, bekleyen öneri + başka yoldan düzeltme → öneri
iptal; DQ-07 / DQ-08 (kısmi teyit yetmez, periyot aşımı yeni dönem); CSV formül güvenliği ve birim kısıtı; kanıt eki tür
(içerikten), boyut ve yol doğrulaması; dışa aktarma ve kanıt indirmede yetki / birim (403 / 404).

## Ortam

| Bileşen | Sürüm |
| --- | --- |
| GLPI | 11.0.8 (resmî paket, ayrı test kurulumu) |
| PHP | 8.3.6 (CLI) |
| Veritabanı | MariaDB 10.11.14, AYRI test veritabanı |
| Fields | kurulu değil |

Testler gerçek müşteri ya da şirket verisinden ayrı, yalnız bu proje için kurulmuş temiz bir GLPI 11 üzerinde
koşuldu. Test verisi (`IQT …` birimler, kullanıcılar, gruplar, durumlar, bilgisayarlar) her koşuda oluşturulup
sonunda silinir. Betikler: `tests/` (pakete dahil değildir).

| Betik | Kapsam |
| --- | --- |
| `iq_test_engine.php` | kural değerlendirici, tarama, bulgu yaşam döngüsü, atama, destek kaydı, puan, birim yalıtımı (`iq_t05.php` ayrı süreçte), yeniden kurulum |
| `iq_test_ui.php` | gerçek sayfa dosyaları ayrı süreçte, oturum açık kullanıcıyla (`iq_page.php`): tüm ekranlar ve POST işlemleri, yetki ve birim yalıtımı |
| `iq_test_cron.php` | GLPI'nin gerçek cron çalıştırıcısı: `front/cron.php --force iqscan` / `iqqueue` |

## 0.1.0 kabul testleri (madde 31)

| No | Senaryo | Sonuç | Kanıt (test kontrolü) |
| --- | --- | --- | --- |
| T01 | Zorunlu alan boş | ✓ | Bir FAIL ve tek bulgu; bilgisayar satırı tarama öncesi / sonrası birebir aynı |
| T02 | Aynı kayıt tekrar / eşzamanlı taranır | ✓ | İki tam tarama + aynı sonucun iki kez uygulanması + iki eşitleme → tek bulgu (tekrar sayısı 7), tek açık takip kaydı |
| T03 | Boş / 0 / false / boş çoklu seçim | ✓ | "   " boş, "0" dolu; sayısal 0 ve false geçerli; açılır listede 0 seçilmemiş; boş liste boş; okunamayan alan "bilinmiyor" |
| T04 | Pasif kullanıcı ve atama | ✓ | Pasif kullanıcı bulgusu birimin veri kalitesi grubuna gitti; sorumlu yoksa "Atama bekliyor"; pasif kişiye elle atama reddedildi |
| T05 | Başka birim kimliğiyle erişim | ✓ (dışa aktarma 3. aşamada) | B kullanıcısı: sayaç / liste ölçütü / GLPI arama / puan yalnız B; A bulgusu detayda 404; üst birim kuralı salt okunur, değiştirme 403 |
| T06 | Onay tamamlanmadı / ret | — | 3. aşama (düzeltme ekranı ve kurala bağlı onay) |
| T07 | Onay sırasında hedef alan değişti | — | 3. aşama (anlık görüntü / CONFLICT) |
| T08 | Düzeltme başarılı / tekrar işleme | ✓ kısmi | GLPI ekranından düzeltme → olay kuyruğu → PASS → çözüm + dönem kapanışı. Eklenti içinden yazma 3. aşamada |
| T09 | Yazma veya denetim adımı hatası | — | 3. aşama (0.1.0 envantere yazmaz) |
| T10 | Destek kaydı elle kapatıldı | ✓ | FAIL sürerken bulgu açık; eski bağlantı saklandı; tutarsızlık denetim izinde; yeni takip kaydı önceki kaydı anıyor |
| T11 | İstisna süresi doldu | — | 3. aşama |
| T12 | Kaynak eksik / tarama kısmi | ✓ | Okunamayan alan → UNKNOWN, bulgu "İnceleme gerekli", çözülmedi; yarıda kalan tarama "Kısmi", bulgular toplu kapatılmadı |
| T13 | Alan adı / kural sürümü değişti | ✓ | Veri tipi değişince kural askıya alındı (görünür neden), düzelince askı kalktı; yeni sürüm kapsamı yeniden taradı, yinelenen bulgu yok |
| T14 | Puan ve kapsam hesabı | ✓ | 7 PASS / 3 FAIL / 2 UNKNOWN ağırlığı → %70 puan, %83,3 kapsam; 0 değerlendirme → "Hesaplanamadı", 0 uygulanabilir → "Kapsam yok" |
| T15 | Eklenti güncellemesi / devre dışı | ✓ kısmi | Yeniden kurulum veriyi korudu, kopya otomatik görev oluşmadı. Devre dışıyken görev / olayların durması GLPI'nin eklenti davranışıdır; ayrıca sınanmadı |

## Ek kontroller

- Kural önizlemesi bulgu, değerlendirme ya da destek kaydı üretmez; değer kümesinde var olmayan kimlik ve aynı kod
  reddedilir; eksik önkoşul değeri anlaşılır hatayla reddedilir.
- Destek kaydı: aynı varlık + aynı sorumlu grup → tek kayıtta 3 bulgu; doğru birim, varlık bağlantısı (tür + kimlik),
  atanan grup, kural kodları içerikte. Bir bulgu çözülünce takip notu; hepsi çözülünce çözüm (Çözüldü).
- Çözülmüş kayıtta yeniden FAIL → yeni dönem ve yeni takip kaydı; "Düzelttim" beyanı + FAIL → bulgu açık kaldı.
- Kural pasifleştirme → aktif bulgular "Kapsam dışı" (çözüm sayılmadı), puandan çıktı. Varlık çöp kutusuna → bulgu
  gerekçeli kapsam dışı.
- Varlık kaydedilince yalnız o kayıt kuyruğa alındı (kaydederken tam tarama yok).
- Denetim izi: servis kimliği (`cron:iqscan`, `cron:iqqueue`) ile kullanıcı ayrı alanlarda.
- Çoklu seçim alan adları `[]` ile çiziliyor (tarayıcı tüm değerleri gönderir).
- Eklenti yetkisi olmayan profil ekranlara erişemez (403).
- Gerçek cron çalıştırıcısı: iki görev de bitiş kaydı bıraktı; görevler harici modda.

## Sınırlar

- Arayüz akışları gerçek oturumlu tarayıcıyla **sınanmadı**: sayfalar CLI'de gerçek dosyalarıyla, oturum açık
  kullanıcıyla çalıştırıldı ve çıktıları statik olarak tarayıcıda incelendi. CSRF denetimi GLPI'nin HTTP katmanında
  yapılır; formlar `_glpi_csrf_token` taşır ama CLI'de bu denetim çalışmaz.
- Bulgular / Kurallar listelerinin satırları GLPI'de AJAX ile yüklenir; satır içeriği aynı arama motorunun verisiyle
  (Search::getDatas) doğrulandı.
- Fields ve GLPI 11 özel varlıkları bu sürümde kapsam dışıdır.
