# Testler (pakete dahil değil)

Ayrı bir GLPI 11 test kurulumunda koşulur (`IQ_ROOT`, `iq_boot.php` içinde). Test kurulumu kendi veritabanını
kullanır; `iq_boot.php` veritabanı adı beklenen test veritabanı değilse durur. Eklenti `IQ_ROOT/plugins/inventoryquality`
altında kurulu ve etkin olmalıdır.

```bash
php iq_test_engine.php   # motor + kabul testleri (T01–T05, T10, T12–T15)
php iq_test_ui.php       # ekranlar ve işlemler (ayrı süreçte gerçek sayfa dosyaları); "snap" ile HTML anlık görüntüleri
php iq_test_cron.php     # GLPI'nin gerçek cron çalıştırıcısı
php iq_test_stage3.php   # düzeltme + onay, çakışma, atomiklik, istisna, DQ-07/08, CSV, kanıt eki (T06–T09, T11)
php iq_test_ui3.php      # 3. aşama ekranları (farklı kullanıcılarla)
php iq_browser_seed.php  # tarayıcı testi için kalıcı veri (IQ_AP1_PASSWORD ortam değişkeni); "clean" ile silinir
```

Test verisi "IQT " / "iqt_" önekiyle oluşturulur ve sonda silinir; eklenti tabloları test başında ve sonunda boşaltılır.
Gerçek müşteri ya da şirket verisi olan bir GLPI'de ÇALIŞTIRMAYIN.
