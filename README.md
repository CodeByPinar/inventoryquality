# inventoryquality — Envanter Veri Kalitesi ve Düzeltme Takibi (GLPI 11)

> **Envanterimizde hangi bilgiler güvenilir, hangileri düzeltilmeli ve bu düzeltmeler gerçekten tamamlandı mı?**

`inventoryquality`, GLPI 11 envanter kayıtlarını kurumun tanımladığı **kalite kurallarına** göre kontrol eden bağımsız
bir eklentidir. Eksik, çelişkili ya da doğrulama süresi geçmiş bilgi için izlenebilir bir **bulgu** açar, düzeltme
işini **sorumluya atar**, gerekirse tek bir **düzeltme destek kaydında** toplar, düzeltmeyi kurala bağlı **onay**
akışından geçirir ve bulguyu **yalnız güncel veride kural yeniden sağlandığında** çözer.

| | |
| --- | --- |
| **Sürüm** | 0.2.0 |
| **GLPI** | 11.0.x (11.0.8 üzerinde test edildi) |
| **PHP** | 8.2+ (8.3.6 üzerinde test edildi) |
| **Veritabanı** | MySQL / MariaDB, InnoDB, utf8mb4 (MariaDB 10.11.14 üzerinde test edildi) |
| **Lisans** | GPLv3+ |
| **Yazar** | Pınar Topuz |
| **Bağımlılık** | Yok (Fields eklentisi gerekmez) |

---

## İçindekiler

1. [Temel ilke](#temel-ilke)
2. [Özellikler](#özellikler)
3. [Nasıl çalışır](#nasıl-çalışır)
4. [Kurulum](#kurulum)
5. [Güncelleme ve kaldırma](#güncelleme-ve-kaldırma)
6. [İlk kullanım — önerilen pilot](#ilk-kullanım--önerilen-pilot)
7. [Kurallar (DQ-01 … DQ-08)](#kurallar-dq-01--dq-08)
8. [Bulgular ve yaşam döngüsü](#bulgular-ve-yaşam-döngüsü)
9. [Sorumlu atama](#sorumlu-atama)
10. [Destek kaydı entegrasyonu](#destek-kaydı-entegrasyonu)
11. [Düzeltme ve onay akışı](#düzeltme-ve-onay-akışı)
12. [İstisnalar](#istisnalar)
13. [İş sahibi teyidi](#iş-sahibi-teyidi)
14. [Kalite puanı ve kapsam](#kalite-puanı-ve-kapsam)
15. [Ekranlar](#ekranlar)
16. [Yetkiler](#yetkiler)
17. [Otomatik görevler](#otomatik-görevler)
18. [Ayarlar](#ayarlar)
19. [Güvenlik](#güvenlik)
20. [Veri modeli](#veri-modeli)
21. [Mimari](#mimari)
22. [Testler](#testler)
23. [Bilinen sınırlar ve yol haritası](#bilinen-sınırlar-ve-yol-haritası)
24. [Sık sorulanlar](#sık-sorulanlar)

---

## Temel ilke

- **Kapanış ilkesi.** Kullanıcının "düzelttim" beyanı, onay verilmesi ya da destek kaydının elle kapatılması
  bulguyu **tek başına çözmez**. Çözüm, güncel verinin ilgili kuralı sağladığını gösteren başarılı bir kontrole dayanır.
- **Teknik hata ≠ uygun veri.** Bir alan okunamazsa sonuç `UNKNOWN` olur; bulgu çözülmez, "İnceleme gerekli"ye düşer.
- **Kapsam dışı ≠ düzeltildi.** Kuralı kapatmak, varlığı silmek ya da kapsamdan çıkarmak bulguyu "Kapsam dışı" yapar;
  çözüm başarısına eklenmez.
- **Onay ≠ doğruluk.** Onaylanan değişiklik uygulandıktan sonra bile kayıt yeniden kontrol edilir.
- **Çekirdeğe dokunmaz.** GLPI dosyaları değiştirilmez; nesne, yetki, olay (hook), arama ve otomatik işlem
  mekanizmaları kullanılır. Varlıklar doğrudan SQL ile değil, GLPI nesne güncellemesiyle değiştirilir.

## Özellikler

- **8 kural şablonu** (DQ-01 … DQ-08): zorunlu alan, sorumlu, pasif kullanıcı, değer kümesi, koşullu zorunluluk,
  referans bütünlüğü, güncellik, iş sahibi teyidi. Serbest SQL / PHP ifadesi / düzenli ifade yoktur.
- **Yan etkisiz önizleme** ve **sürümleme**: kural yayına alınmadan örnek kayıtlardaki etkisi görülür; her yayın yeni
  sürümdür, kimin ne zaman değiştirdiği saklanır.
- **Kararlı alan kataloğu**: alanlar kararlı anahtarlarla (`core:locations_id`, `rel:groups_tech`, `virt:responsible`,
  `agent:last_contact`, `attest:…`) tutulur; alan kaybolur ya da tipi değişirse kural **askıya** alınır.
- **Partili, devam ettirilebilir tarama** + **olay kuyruğu**: kayıt kaydedilince yalnız o kayıt yeniden kontrol edilir.
- **Tekilleştirilmiş bulgular** ve **dönemler**: aynı sorun tekrar tekrar açılmaz; tekrar eden sorun yeni dönem olur.
- **Sorumlu atama** (kural → varlığın teknik grubu / sorumlusu → birimin veri kalitesi grubu → Atama bekliyor).
- **Destek kaydı köprüsü**: varlık + sorumlu başına tek kayıt, takip notları, yalnız doğrulanmış çözüm, erken kapanma
  politikası.
- **Düzeltme ve onay**: eklenti içinden öneri, kurala bağlı tek / iki sıralı onay, anlık görüntü ve çakışma kontrolü,
  atomik yazım, kanıt eki.
- **Süreli istisnalar** ve süre dolumunda otomatik yeniden kontrol.
- **İş sahibi teyidi** (otomatik envanterden ayrı).
- **Açıklanabilir puanlama**: kalite puanı + değerlendirme kapsamı birlikte.
- **Güvenli CSV dışa aktarma**, **denetim izi** (kullanıcı ve servis kimliği ayrı), **10 ayrı profil yetkisi**,
  **kurum birimi yalıtımı**.

## Nasıl çalışır

```
           ┌────────────────────── Tarama / yeniden kontrol ◄──────────────────────┐
           ▼                                                                         │
  Kuralı değerlendir ──► PASS ─► (bulgu varsa) Çözüldü ─► destek kaydına çözüm       │
           │                                                                         │
           ├──► FAIL ─► Bulgu aç / güncelle ─► Sorumluya ata ─► (destek kaydı)       │
           │                     │                                                   │
           │                     └─► Düzeltme: GLPI ekranından / eklentiden          │
           │                          (gerekiyorsa onay) ─► uygula ─────────────────┘
           │
           ├──► UNKNOWN ─► İnceleme gerekli (çözülmez)
           └──► NOT_APPLICABLE ─► puana girmez (bulgu varsa Kapsam dışı)
```

**Örnek senaryo.** `LT-042` adlı bilgisayarın konumu boş ve bağlı kullanıcısı pasif. İki kural uygunsuz çıkar; sistem
iki bulgu açar ve aynı varlık + aynı sorumlu grup için **tek** düzeltme destek kaydında toplar. Teknisyen konumu GLPI
ekranından girer; olay kuyruğu kaydı yeniden kontrol eder, konum bulgusu çözülür, kayda takip notu düşer. Kullanıcı
değişikliği onay gerektiriyorsa eklentiden önerilir, onaylanınca uygulanır ve yeniden kontrol edilir. İki bulgu da
çözülünce destek kaydına çözüm eklenir; kapanışı GLPI'nin kendi politikası yürütür.

## Kurulum

### Gereksinimler

- GLPI 11.0.x, PHP 8.2+, sunucu cron'u (otomatik görevler harici modda çalışır).
- Kurulumu yapan profilde yapılandırma güncelleme yetkisi.

### Paketten

```bash
# 1) Paketi eklenti dizinine açın
sudo tar xzf /tmp/inventoryquality-0.2.0.tgz -C /var/www/glpi/plugins
sudo chown -R www-data:www-data /var/www/glpi/plugins/inventoryquality

# 2) Kurun ve etkinleştirin (yönetici kullanıcı adınızla)
sudo -u www-data php /var/www/glpi/bin/console glpi:plugin:install inventoryquality -u <yönetici>
sudo -u www-data php /var/www/glpi/bin/console glpi:plugin:activate inventoryquality
```

Kaynak koddan kurmak için depoyu `plugins/inventoryquality` dizinine klonlayın (`tests/` dizini gerekmez).

Kurulum şunları yapar:

- 15 tablo oluşturur (`glpi_plugin_inventoryquality_*`, yalnız yoksa),
- yapılandırma güncelleme yetkisi olan profillere tüm eklenti haklarını verir (diğerleri 0),
- üç otomatik görev kaydeder (`iqqueue`, `iqscan`, `iqexpire`),
- alan kataloğunu oluşturur ve kuralları doğrular,
- GLPI'nin derlenmiş şablon önbelleğini temizler (güncellemeden sonra eski ekran görünmesin diye).

Menü: **Araçlar → Envanter Veri Kalitesi**. Menünün görünmesi için oturumu kapatıp açın.

### Sunucu cron'u

```cron
* * * * * www-data /usr/bin/php /var/www/glpi/front/cron.php
```

## Güncelleme ve kaldırma

- **Güncelleme:** yeni paketi aynı dizine açın, `glpi:plugin:install` (gerekirse `--force`) + `glpi:plugin:activate`.
  Veri korunur; yeni tablolar / görevler eklenir, kopya görev oluşmaz. GLPI sürüm değişince eklentiyi pasifleştirir;
  Eklentiler sayfasından ya da konsoldan yeniden etkinleştirin. **"Kaldır" yapmayın** — tüm veri silinir.
- **Devre dışı bırakma** hiçbir kaydı silmez; olaylar ve otomatik görevler durur.
- **Kaldırma** eklentinin **tüm** tablolarını, ayarlarını, profil yetkisini, otomatik görevlerini ve kanıt dosyalarını
  siler. Açılmış GLPI destek kayıtları GLPI'de kalır.

## İlk kullanım — önerilen pilot

1. **Ayarlar → Kurum birimi ayarları:** pilot birim için "Envanter Veri Kalitesi" destek grubunu seçin. Destek kaydı
   modu varsayılan olarak **yalnız bulgu**dur.
2. **Kurallar → Şablondan yeni kural:** şablon seçin, hedef alanı / değerleri girin, **Önizle** ile etkisini görün,
   **Yeni sürüm olarak yayınla**, **Etkinleştir**.
3. **İşler → Tam tarama başlat.** Sonuçlar **Genel Bakış** ve **Bulgular** ekranlarında.
4. Bulgu hacmi makulse birimde destek kaydı modunu **Aç** yapın; düzeltme gerektiren kurallar için onay politikası
   tanımlayın.

## Kurallar (DQ-01 … DQ-08)

| Şablon | Uygunsuzluk koşulu | Düzeltme yaklaşımı |
| --- | --- | --- |
| **DQ-01** Zorunlu alan | Kapsamdaki varlıkta zorunlu alan boş | Yetkili kişi geçerli değer girer |
| **DQ-02** Sorumlu | Aktif varlıkta aktif teknik sorumlu kişi ya da teknik grup yok | Sorumluluk ataması (gerekirse onaylı) |
| **DQ-03** Pasif kullanıcı | Bağlı kullanıcı pasif, silinmiş ya da süresi dolmuş | Geçerli ilişki atanır |
| **DQ-04** Değer kümesi | Alan izin verilen seçeneklerin dışında | Tanımlı seçeneklerden biri seçilir |
| **DQ-05** Koşullu zorunluluk | Önkoşul sağlanınca (ör. durum "Kullanımda") hedef alan boş | Kapsam koşulu + eksik bilgi birlikte |
| **DQ-06** Referans bütünlüğü | Alan artık bulunmayan bir kayda bağlı | Doğru referans seçilir; tahmin yapılmaz |
| **DQ-07** Güncellik | Veri kaynağının son görülme tarihi N günü aşmış ya da yok | Ajan / kaynak incelenir; tarih elle ileri alınmaz |
| **DQ-08** İş sahibi teyidi | Seçili alanlar periyotta iş sahibince teyit edilmemiş | İş sahibi teyit verir |

**Kural tanımı:** kararlı kod (değişmez, tekil) · ad · kurum birimi + alt birim politikası · varlık tipi · durum kapsamı
· koşul · önem (Düşük / Orta / Yüksek / Kritik) · ağırlık (1–100) · süre (gün; 0 = birim varsayılanı) · sorumlu grup /
kişi · destek kaydı politikası (birim ayarına göre / açma) · düzeltme onay politikası.

**Operatörler:** boş / dolu, eşit / eşit değil, kümede / küme dışında, tarih eşiği (N günden eski / son N gün),
bağlı kayıt mevcut / aktif.

**Boşluk kuralı (veri tipine göre):** yalnız boşluklardan oluşan metin boştur; `"0"` metni, sayısal `0` ve `false`
geçerli değerdir; açılır listede `0` "seçilmemiş" demektir; boş çoklu seçim ayrıca değerlendirilir.

**Sonuçlar:** `PASS` uygun · `FAIL` uygunsuz (bulgu) · `UNKNOWN` değerlendirilemedi (inceleme) · `NOT_APPLICABLE`
önkoşul sağlanmadı (puana girmez).

## Bulgular ve yaşam döngüsü

Kararlı anahtar: **kurum birimi + varlık tipi + varlık kimliği + kural + hedef alan**. Aynı anahtarda tek güncel bulgu
bulunur; benzersizlik kısıtı eşzamanlı işçilerde bile aynı bulguyu korur. Kural sürümü anahtarın parçası değildir.

| Durum | Anlamı |
| --- | --- |
| Açık | Kural FAIL döndü; düzeltme bekleniyor |
| İşlemde | Sorumlu işi üstlendi |
| Onay bekliyor | Düzeltme önerisi onay bekliyor |
| Doğrulama bekliyor | Düzeltme bildirildi / uygulandı; güncel veri henüz kontrol edilmedi |
| Çözüldü | Güncel veride PASS; doğrulama kaydı mevcut |
| İstisna | Yetkili kişi gerekçe ve bitiş tarihiyle erteledi; sorun çözülmüş sayılmaz |
| İnceleme gerekli | Veri okunamadı, onaylayan bulunamadı ya da işlem tamamlanamadı |
| Kapsam dışı | Varlık / kural kapsamdan çıktı; sebep kaydedilir, çözüm sayılmaz |

Çözülmüş kayıtta aynı kural yeniden FAIL verirse **yeni dönem** açılır; önceki dönem korunur. İlk / son görülme, tekrar
sayısı ve her dönem saklanır.

## Sorumlu atama

1. Kuralda tanımlı aktif ve yetkili grup / kişi
2. Varlığın teknik grubu / aktif teknik sorumlusu
3. Birimin "Envanter Veri Kalitesi" destek grubu
4. Hiçbiri yoksa **Atama bekliyor** kuyruğu (Genel Bakış'ta uyarı)

Kullanıcı aktif, silinmemiş, geçerlilik tarihleri içinde ve varlığın biriminde profili olmalıdır; grup var, atanabilir ve
birimden görünür olmalıdır. Pasif kullanıcı hiçbir zaman atanmaz.

## Destek kaydı entegrasyonu

- Aynı birim + aynı varlık + aynı sorumlu için **tek açık düzeltme kaydı**; bir kayıt birden çok bulguyu taşır; farklı
  birimler birleştirilmez. Atama bekleyen bulgu için kayıt açılmaz.
- Kayıt "İstek" türünde açılır, varlığa tür + kimlikle bağlanır, sorumluya atanır; içerikte kural kodları, beklenen ve
  mevcut durumun maskelenmiş özeti, kontrol zamanı ve hedef tarih bulunur.
- Yeni bulgular, düzeltme / onay / ret olayları **takip notu** olarak eklenir.
- Tüm bağlı bulgular PASS ile çözülünce **çözüm** eklenir (doğrudan Kapalı yapılmaz; GLPI'nin çözüm onayı ve otomatik
  kapanış politikası korunur). İstisna / kapsam dışı ile kapanışta metin "düzeltildi" demez.
- Kayıt bulgular çözülmeden kapatılırsa bulgular açık kalır, tutarsızlık denetim izine yazılır, eski bağlantı saklanarak
  **yeni takip kaydı** açılır.

## Düzeltme ve onay akışı

**Üç yol:**

1. **GLPI ekranından düzeltme** — kayıt kaydedilince olay kuyruğu yeniden kontrol eder.
2. **Eklenti içinden yetkili düzeltme** — onaysız politikada "Düzeltme uygula" yetkilisi doğrudan uygular.
3. **Onaylı düzeltme** — öneri, kuraldaki onay adımlarından geçmeden envantere yazılmaz.

**Onay politikası** (kuraldan gelir; öneren gevşetemez):

| Seçenek | Davranış |
| --- | --- |
| Onaysız | Yetkili kişi doğrudan uygular |
| 1 onay adımı | Grup ve/veya kişi |
| 2 sıralı onay adımı | 1. adım tamamlanmadan 2. adım açılmaz |
| Biri yeterli (any) | Gruptan bir yetkili onaylar |
| Belirlenmiş herkes (all) | Gruptaki her aktif üye (öneren hariç) onaylamalı |
| Kendi talebini onaylama | Varsayılan **kapalı** |

Ret durumunda öneri reddedilir (gerekçe zorunlu), envanter değişmez, bulgu açık kalır. Onaylayan bulunamaz ya da
pasifleşirse iş **İnceleme gerekli**ye düşer; onay otomatik verilmez; yetkili yeniden atama geçmişe yazılır.

**Güvenli uygulama** (`CorrectionProcessor`):

```
claimOnce → reloadAssetAndPolicy → checkEntityRightsAndRequiredApprovals
→ validateAllowedFieldsTypesAndReferences → compareTargetFieldsWithSnapshot
→ applyWithVerifiedAdapterAndAudit → enqueueVerificationAndNotifications → APPLIED (doğrulama bekliyor)
```

- Öneri anında hedef alanın değeri, varlığın birimi ve kural sürümü **anlık görüntü** olarak saklanır.
- Uygulamada varlık satırı aynı veritabanı işleminde **kilitlenir** (`SELECT … FOR UPDATE`); hedef alan, birim ya da
  onay politikası değişmişse **CONFLICT** — yazma yapılmaz. İlgisiz alanın değişmesi çakışma değildir.
- GLPI nesne güncellemesi, GLPI tarihçesi ve eklenti denetim kaydı **aynı işlemde**; herhangi bir adım başarısızsa
  hepsi geri alınır, düzeltme "uygulandı" işaretlenmez ("Yeniden dene" ile tekrar denenebilir).
- Her düzeltmenin tekil işlem anahtarı vardır; tekrar çalışan iş değişikliği ikinci kez uygulamaz.
- Kanıt eki: PDF / PNG / JPG / düz metin, en fazla 5 MB; tür dosya içeriğinden belirlenir.

## İstisnalar

- **Gerekçe**, **onaylayan** (istisnayı veren yetkili), **kapsam** (bulgu), **bitiş tarihi** (en fazla 365 gün) zorunlu;
  telafi edici işlem isteğe bağlı.
- İstisna bir yaşam döngüsü durumudur: altındaki FAIL'i PASS yapmaz, puanı değiştirmez, istisna sayısı ayrıca görünür.
- Süre dolunca (saatlik görev) kayıt yeniden kontrol edilir: FAIL → Açık, PASS → Çözüldü, UNKNOWN → İnceleme gerekli.
- İstisna sürerken veri düzelirse bulgu PASS ile çözülür, istisna sona erer. Yetkili istisnayı geri alabilir.

## İş sahibi teyidi

DQ-08 kuralı seçili alanların (ör. kullanıcı, konum) belirlenen periyotta iş sahibince teyit edilmesini ister. Teyit,
varlığın **Veri Kalitesi** sekmesinden ya da bulgu ekranından verilir; **kimin, hangi alanları, hangi değerlerle ve ne
zaman** teyit ettiği kaydedilir. Teyit otomatik envanter gelişinden ayrıdır ve veriyi değiştirmez.

Teyit verebilen: varlığın kullanıcısı, teknik sorumlusu, teknik grubunun üyesi ya da "Düzeltme uygula" yetkilisi.

## Kalite puanı ve kapsam

```
Kalite puanı          = 100 × PASS ağırlığı / (PASS + FAIL ağırlığı)
Değerlendirme kapsamı = 100 × (PASS + FAIL ağırlığı) / (PASS + FAIL + UNKNOWN ağırlığı)
```

Örnek: PASS 7, FAIL 3, UNKNOWN 2 ağırlık → puan **%70**, kapsam **%83,3**. Değerlendirilen ağırlık 0 ise
"Hesaplanamadı", uygulanabilir kontrol yoksa "Kapsam yok" gösterilir (ikisi de %100 yapılmaz). Düşük kapsamda puan
"sağlıklı" etiketiyle sunulmaz. Kurum puanı ağırlıkların toplamından hesaplanır; ölçüm tanımlı kurallara uyumu gösterir,
fiziksel gerçekliğin ya da mevzuat uyumunun tamamını kanıtlamaz.

## Ekranlar

| Ekran | İçerik |
| --- | --- |
| **Genel Bakış** | Puan + kapsam, açık / gecikmiş / atama bekleyen / incelemedeki bulgular, kural bazında sonuçlar, yaş dağılımı, düzeltme ve istisna göstergeleri, tarama sağlığı, CSV |
| **Bulgular** | GLPI arama motoruyla liste: varlık, kural, durum, önem, sorumlu, hedef tarih, son kontrol filtreleri |
| **Bulgu Detayı** | Beklenen / mevcut durum, dönemler, destek kayıtları, düzeltme önerileri, istisna, teyit, işlem geçmişi; Yeniden kontrol · Üstlen · Düzelttim · Ata · Düzeltme öner · İstisna ver |
| **Düzeltmeler** | Onayımı bekleyenler · Taleplerim · Tümü; önceki / yeni değer, anlık görüntü, kanıt, onay adımları; Onayla · Reddet · Uygula · İptal · Yeniden ata |
| **İstisnalar** | Geçerli / süresi dolan / geri alınan / sona eren; geri alma |
| **Kurallar** | Şablondan oluşturma, önizleme, sürümler, etkinleştirme, onay politikası |
| **İşler** | Taramalar, iş kuyruğu, başarısız işleri yeniden deneme, otomatik görev durumu |
| **Ayarlar** | Kurum birimi ayarları, genel ayarlar, alan kataloğu |
| **Varlık → Veri Kalitesi** | Varlığın bulguları, uygulanan kurallar, puan / kapsam, teyitler |

## Yetkiler

**Yönetim → Profiller → (profil) → Envanter Veri Kalitesi.** Bir işlem yetkisi diğerlerini otomatik vermez.

| Yetki | İzin verdiği |
| --- | --- |
| Görüntüle | Ekranlar, varlık sekmesi |
| Kural yönet | Kural oluşturma, yayınlama, etkinleştirme |
| Tarama başlat / yeniden kontrol | Tam tarama, kuyruk, yeniden kontrol |
| İş ata | Elle atama, onaylayanı yeniden atama |
| Düzeltme öner | Eklenti içinden öneri |
| Düzeltme uygula | Onaysız / onaylanmış düzeltmeyi uygulama (varlıkta güncelleme yetkisi de gerekir) |
| Onayla | Onay adımında karar |
| İstisna ver | İstisna verme / geri alma |
| Rapor dışa aktar | CSV |
| Ayar yönet | Ayarlar ekranı |

Liste, detay, sayaç, arama, dışa aktarma ve ek indirme **kurum birimi** kısıtından geçer. Alt birim kullanıcısı üst
birimin (alt birimlere uygulanan) kuralını görür ama değiştiremez.

## Otomatik görevler

| Görev | Sıklık | İş |
| --- | --- | --- |
| `iqqueue` | 5 dakika | Olay kuyruğu (yeniden kontrol), destek kaydı eşitleme, süren taramaları ilerletme |
| `iqscan` | Günde bir (01:00–05:00) | Tam tarama; kaçırılan olayları yakalar |
| `iqexpire` | Saatlik | Süresi dolan istisnalar; onaylayanı pasifleşen düzeltmeler |

Görevler **harici** modda kayıtlıdır. Tarama varsayılan 200 kayıtlık partiler ve zaman bütçesiyle çalışır, kaldığı yeri
saklar; yarıda kalan tarama "Kısmi" görünür ve değerlendirilmeyen bulgular topluca kapatılmaz.

## Ayarlar

**Kurum birimi ayarları** (alt birim üst birimin ayarını devralır): veri kalitesi destek grubu · destek kaydı modu
(yalnız bulgu / aç) · talep sahibi servis kimliği · varsayılan hedef süre.

**Genel ayarlar:** tarama partisi · tarama ve kuyruk zaman bütçeleri · başarısız iş deneme sayısı · önizleme örnek
sayısı · düşük kapsam eşiği.

## Güvenlik

- Durum değiştiren tüm isteklerde GLPI CSRF koruması; Twig ile otomatik çıktı kaçışlama.
- SQL değerleri GLPI sorgu oluşturucusuyla bağlanır; alan ve tablo seçimi doğrulanmış katalogla sınırlıdır; kullanıcı
  serbest sorgu çalıştıramaz.
- CSV'de `=`, `+`, `-`, `@` ile başlayan hücreler formül olarak yorumlanmasın diye tek tırnakla yazılır.
- Kanıt dosyaları rastgele adla, eklenti belge dizininde saklanır; tür içerikten belirlenir; yalnız yetkili ve birim
  erişimi olan kullanıcıya "attachment" olarak sunulur.
- Sistem işlemleri servis kimliğiyle (`cron:iqscan`, `cron:iqqueue`, `cron:iqexpire`, `service:recheck`,
  `service:correction`, `service:manual`) kaydedilir; talep eden ve onaylayan ayrı alanlardadır.

## Veri modeli

Önek: `glpi_plugin_inventoryquality_`

| Tablo | İçerik |
| --- | --- |
| `rules` / `ruleversions` | Kararlı kural kimliği, kapsam, yayındaki sürüm / tanım, önem, ağırlık, politika |
| `fieldmaps` | Alan kataloğu: tip, adaptör, kararlı anahtar, veri tipi, okuma-yazma, GLPI sürümü |
| `scanruns` / `evaluations` | Taramalar (kaldığı yer, sayaçlar, kilit) / varlık × kural güncel sonucu |
| `findings` / `cycles` | Bulgular (kararlı anahtar) / açılma–kapanma dönemleri |
| `ticketlinks` | Bulgu ↔ destek kaydı ↔ dönem, gruplama anahtarı |
| `corrections` / `approvals` | Öneriler (değişiklik, anlık görüntü, politika, kanıt) / onay adımları |
| `exceptions` / `attestations` | Süreli istisnalar / iş sahibi teyitleri |
| `jobs` | Dayanıklı iş kuyruğu (tekil anahtar, kilit, deneme) |
| `auditlogs` | Denetim izi |
| `entityconfigs` | Kurum birimi ayarları |

## Mimari

```
setup.php / hook.php        Eklenti tanımı, menü, yetkiler, olaylar, yaşam döngüsü
src/RuleEvaluator.php       Saf değerlendirici (veri + kural → sonuç)
src/RuleTemplates.php       DQ şablonları, tanım üretimi, onay politikası
src/Catalog.php             Alan kataloğu (keşif, kararlı anahtarlar, senkron)
src/AssetAdapter.php        Güncel veriyi normalize okuma
src/ScanRunner.php          Partili tarama, yeniden kontrol, önizleme
src/FindingService.php      Tekilleştirme, dönemler, durum geçişleri
src/AssignmentResolver.php  Sorumlu bulma
src/TicketBridge.php        Destek kaydı oluşturma / bağlama / çözüm
src/CorrectionService.php   Öneri, onay, ret, yeniden atama
src/CorrectionProcessor.php Güvenli uygulama (kilit, anlık görüntü, atomik yazım)
src/ExceptionService.php    Süreli istisnalar
src/AttestationService.php  İş sahibi teyidi
src/QualityCalculator.php   Puan ve kapsam
src/JobQueue.php / Cron.php Kuyruk ve otomatik görevler
src/Export.php / Evidence.php  Güvenli CSV, kanıt dosyaları
front/ · templates/         Sayfa denetleyicileri ve Twig ekranları
tests/                      Davranış testleri (pakete dahil değil)
```

## Testler

Testler ayrı, temiz bir GLPI 11 kurulumunda (kendi veritabanıyla) koşulur; test verisi oluşturulur ve sonda silinir.
**Gerçek veri içeren bir GLPI'de çalıştırmayın.** Ayrıntı: [`tests/README.md`](tests/README.md),
sonuçlar: [`TEST-RAPORU.md`](TEST-RAPORU.md).

| Test grubu | Kontrol |
| --- | --- |
| Motor (`iq_test_engine.php`) | 55 |
| Ekranlar (`iq_test_ui.php`) | 38 |
| Otomatik görevler (`iq_test_cron.php`, gerçek `front/cron.php`) | 6 |
| 3. aşama motor (`iq_test_stage3.php`) | 60 |
| 3. aşama ekranlar (`iq_test_ui3.php`) | 26 |
| **Toplam** | **185 / 185** |

## Bilinen sınırlar ve yol haritası

- Doğrulanmış varlık tipi: **Computer**. Monitor, Printer, Phone, NetworkEquipment aynı testleri geçince açılacak.
- Fields eklentisi ve GLPI 11 özel varlık adaptörleri, native Forms ile öneri oluşturma: sonraki aşama.
- Düzeltme önerisi bulgu başına tek alan (veri modeli çoklu alanı destekler).
- GLPI'nin kendi liste dışa aktarması (Bulgular listesindeki CSV/PDF düğmesi) eklentinin "Rapor dışa aktar" yetkisini
  denetlemez; yetki denetimli ve formül güvenli dışa aktarma Genel Bakış'taki CSV bağlantısıdır.
- Arayüz akışları CLI'de gerçek sayfa dosyalarıyla test edildi; gerçek oturumlu tarayıcı testi henüz tamamlanmadı.

## Sık sorulanlar

**Bulguyu neden elle kapatamıyorum?** Kapanış ilkesi gereği: bulgu yalnız güncel veride kural sağlanınca çözülür.
Veriyi düzeltin ve "Yeniden kontrol et"e basın ya da birkaç dakika bekleyin.

**Destek kaydı neden açılmadı?** Birim "yalnız bulgu" modunda olabilir, kuralda destek kaydı kapalı olabilir ya da bulgu
"Atama bekliyor" durumundadır.

**Güncellemeden sonra ekranlar eski görünüyor.** Kurulum şablon önbelleğini temizler; yine de görünüyorsa
`php bin/console cache:clear` çalıştırın.

**Görevler çalışmıyor.** Görevler harici moddadır; sunucuda GLPI cron satırının olduğundan emin olun
(**Kurulum → Otomatik İşlemler**).

---

Lisans: GPLv3+ — ayrıntı için [`LICENSE`](LICENSE).
