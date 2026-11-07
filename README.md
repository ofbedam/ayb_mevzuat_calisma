# AYB Mevzuat Çalışma Alanı

Bu modül, mevzuat taslakları ile madde ve görüş çalışmalarının uygulama kodunu ve editör çalışma alanını yönetir. Medya/dosya normalizasyon modülünden bağımsızdır.

## Özel çalışma alanı

Modül, Drupal içerik ve moderasyon altyapısını değiştirmeden editörlere üç panelli bir mevzuat çalışma alanı sağlar:

- `/admin/content/mevzuat-taslaklari`: filtrelenebilir taslak listesi,
- `/admin/content/mevzuat-taslaklari/{taslak}`: belge ağacı, taslak özeti ve inceleme paneli,
- `/admin/content/mevzuat-taslaklari/{taslak}/madde/{madde}`: taslak bağlamını koruyan madde çalışma ekranı.

Tam düzenleme formları Drupal'ın standart entity formudur. Çalışma alanı, bu formlara `destination` ile döner; hızlı madde düzenleme, önizleme ve revizyon geçmişi ise erişim denetimli modal olarak açılır. Böylece çekirdek içerik, çeviri, revizyon ve içerik moderasyonu akışları korunur.

Çalışma alanındaki belge ağacı, kayıt sırasındaki kısım/bölüm/alt başlık kayıtlarını açılır hiyerarşi olarak gruplar. Sağ panelden izin verilen moderasyon geçişleri doğrudan açılabilir; taslağa veya seçili maddeye bağlı öneri ve görüş kayıtları da aynı panelde listelenir. Önceki üç yönetim Views yolu (`mevzuat-taslaklari`, `taslak-maddeleri`, `taslak-gorusleri`) tek çalışma alanına kalıcı yönlendirme yapar.

## Sorumluluklar

Madde bazlı çalışma için ayrıntılı kullanıcı yönergesi: `MADDE_BAZLI_CALISMA_YONERGESI.md`.

- `mevzuat_taslagi`, `mevzuat_taslak_maddesi` ve `mevzuat_taslak_gorusu` sayfa, form ve erişim davranışları.
- Taslak çalışma modeli alanı ve model tutarlılığı doğrulamaları.
- Editör çalışma alanı: `/admin/content/mevzuat-taslaklari`.
- Taslak, madde ve görüş kayıtlarında revizyon geçmişi ve alan bazlı sürüm karşılaştırması.
- Modüle ait CSS, route, menü bağlantısı ve izin tanımları.

Çalışma ağacında aynı seviyede sürükle-bırak sıralama ve yapısal kayıtların (kitap/kısım/bölüm/alt başlık/madde başlığı) altına güvenli taşıma desteklenir. Üst kayıt türü, taslak sahipliği, döngü ve CSRF kontrolleri endpoint üzerinde de doğrulanır; yalnızca tarayıcı tarafındaki işaretlemeye güvenilmez.

Görsel olarak sabit HTML üreten çalışma bölümü navigasyonu ve fark açıklaması Twig şablonlarına taşınmıştır. Böylece tema override'ı ve erişilebilirlik iyileştirmeleri modül koduna dokunmadan yapılabilir.

Mevcut taslaklar “Tam metin” modelinde korunur. “Madde bazlı” modelde madde kayıtları kaynak metindir ve taslak sayfası bunları sıralı bir önizleme olarak gösterir. Uzun metinden otomatik madde çıkarımı yapılmaz. İçerik girildikten sonra model değişikliği doğrulama katmanında engellenir.

`ayb_media_normalizasyon.install` içindeki eski 10038–10052 güncelleme fonksiyonları, hâlihazırda uygulanmış Drupal güncelleme geçmişidir. Drupal güncelleme kimlikleri modül adına bağlı olduğundan geriye dönük taşınmamıştır; yeni çalışma zamanı kodu ve yeni model alanının kurulum sahipliği bu modüldedir. Yeni taslak geliştirmeleri yalnızca bu modüle eklenmelidir.

Editör rolü taslak, madde ve görüş kayıtlarını oluşturup düzenleyebilir. Taslağı onaylama, yayımlama ve arşivleme geçişleri yönetici yetkisinde kalır.

## Editoryal snapshot ve kamu sürümleri

Drupal revizyonları ve `autosave_form` kayıtları çalışma geçmişidir; kamuya açık sürüm olarak yorumlanmaz. Editör taslak çalışma alanındaki **Editoryal sürümler** bağlantısından `/admin/content/mevzuat-taslaklari/{taslak}/snapshot/yeni` yoluyla sabit bir belge görüntüsü oluşturabilir. İşlem, taslağa bağlı madde ağacını, sıralamayı, alan içeriklerini ve o andaki moderasyon durumlarını kaydeder; hiçbir maddeyi yayımlamaz veya durumunu değiştirmez.

Editoryal sürüm listesi `/admin/content/mevzuat-taslaklari/{taslak}/snapshotlar` adresindedir. Kamuya açma yetkisi ayrı `publish ayb draft snapshots` iznidir ve varsayılan olarak yönetici rolüne verilir. Yayımlama yalnızca ana taslak Drupal’da yayımlanmışken yapılabilir. Açılan sürümün payload’ı değişmez; geri çekme onu silmez, yalnızca kamu görünürlüğünü kaldırır.

Anonim ziyaretçi taslak sayfasındaki **Önceki yayımlanmış sürümler** bağlantısından `/mevzuat-snapshot/taslak/{taslak}` sayfasına ulaşır. Editoryal snapshot içeriklerini görüntüleme ve karşılaştırma rotaları anonim kullanıcıya kapalıdır. Sürüm karşılaştırması bağlı madde kayıtlarını, metin/gerekçe alanlarını ve hiyerarşi-sıra farklarını; tam metin modelinde de metin ve genel gerekçeyi inceler.

Görüntüleme sayfasında çalışma özeti ve revizyon araçları; düzenleme sayfasında ise metin alanının yanında veya madde bazlı çalışma formunun üstünde revizyon kısayolları bulunur. Revizyon geçmişi ve karşılaştırma Drupal modal penceresinde açılır. Karşılaştırma taslak metni, madde metni/gerekçesi ve görüş/cevap alanlarını iki sürüm arasında ayrı ayrı inceler; metin farkları Unicode karakter düzeyinde işaretlenir. Erişim Drupal'ın `view all revisions` yetkisine bağlıdır. Bu özellik, yürürlükteki metin ile güncel taslak arasındaki karşılaştırmadan bağımsız olarak kaydedilmiş revizyonları karşılaştırır.
 
## Drupal testleri

Drupal PHPUnit standardına uygun Kernel testi `tests/src/Kernel/DraftArticleRenderTest.php` dosyasındadır. Test, madde kayıtlarının üst taslak sayfasında güvenli biçimde render edilmesini ve başlık hizalama seçeneklerinin çıktıya yansımasını kontrol eder. Mevcut Drush entegrasyon testi de ayrıca korunur.

PHPUnit bağımlılığı kurulu bir geliştirme ortamında test sınıfı şöyle çalıştırılır:

```bash
php web/core/scripts/run-tests.sh \
  --class 'Drupal\\Tests\\ayb_mevzuat_calisma\\Kernel\\DraftArticleRenderTest' \
  --url=https://localhost/ayb
```
