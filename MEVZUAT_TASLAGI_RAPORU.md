# Mevzuat taslağı iyileştirmesi — 26.10.2026

## Karşılaştırma

İncelenen resmî kaynaklar:

- [Avrupa Komisyonu LEOS](https://interoperable-europe.ec.europa.eu/collection/justice-law-and-security/solution/leos-open-source-software-editing-legislation): yapılandırılmış taslak, görüş, öneri, versiyon ve inceleme.
- [Better Regulation](https://commission.europa.eu/law/law-making-process/better-regulation_en): etki değerlendirmesi, paydaş görüşleri, uygulama ve değerlendirme.
- [Cabinet Office, Guide to Making Legislation](https://www.gov.uk/government/publications/guide-to-making-legislation/guide-to-making-legislation-html--2): gerekçe, hüküm bazlı açıklamalar, inceleme ve uygulama planlaması.

Bu, ürünlerin işlevsel tasarımından yararlanan bir karşılaştırmadır; hukukî uygunluk belgelendirmesi değildir.

| Tespit | Uygulama |
|---|---|
| Taslak listesi sadece yayımlananları gösteriyordu | Yayımlanmamış kayıtlar yönetim listesine alındı |
| Madde ve görüş listeleri yoktu | Ayrı admin Views, üst taslak filtresi ve durum filtreleri |
| Senaryo sadece istemci tarafında etkiliydi | Senaryo zorunlu; değişiklikte mevcut mevzuat ilişkisi doğrulanıyor |
| İlk madde/görüş kaydı doğrulamada reddedilebiliyordu | Ortak workflow'a eksik draft → draft geçişi eklendi |
| Farklı taslağın maddesine görüş bağlanabiliyordu | Üst taslak ve madde tutarlılığı kontrolü |
| Ters görüş tarihleri mümkündü | Bitiş başlangıçtan önce olamaz |
| Gerekçesiz görüş kararı mümkündü | Kabul/kısmen kabul/ret için idarenin cevabı gerekli |
| Nihai metin olmadan onay mümkündü | Nihai metin ve onay notu onay/yayımda gerekli |
| Görüş gizliliği bir liste seçeneğiydi | Görüş sayfalarına yetki kontrolü |
| Görüş sayısı elle tutuluyordu | İlişkili kayıtların canlı sayısı gösteriliyor |
| Fark yalnızca açıklama alanıydı | Satır bazlı eklenen/çıkarılan metin gösterimi |
| Sayfa düz alan listesiydi | Künye ızgarası, renkli bölümler, akordiyonlar ve çalışma bağlantıları |
| Yeni taslak formu standart form görünümündeydi | Açıklama paneli, 4 adımlı süreç göstergesi, renkli alan grupları, odak stilleri, responsive alanlar ve sabit işlem çubuğu |

## Alanlar ve iş akışı

Yeni alanlar:

- `field_taslak_dosya_no`: çalışma/dosya numarası.
- `field_taslak_uygulama_plani`: uygulama ve izleme planı; çevrilebilir.
- `field_taslak_onay_notu`: onay/karar gerekçesi; çevrilebilir.
- `field_taslak_sozlesmeler`: ilgili sözleşmeler; diller arasında ortak.
- `field_taslak_onceki`: önceki taslak çalışması; diller arasında ortak, kendine ilişki engellenir.

Senaryo, madde ve görüşün üst taslak ilişkisi, madde numarası ve görüş metni zorunlu yapıldı. Sayısal alanların yanlış metin widget'ları düzeltildi.

`field_taslak_maddeler` yürürlükteki `mevzuat_maddesi` kayıtlarını hedefler; etiketi “İlgili mevcut mevzuat maddeleri” olarak düzeltildi. Yeni taslak maddeleri kendi `field_taslak_madde_taslak` alanıyla bağlanır ve otomatik listelenir. Önceki testin mevcut madde alanına taslak maddesi kimliği yazması doğru bir ilişki testi değildi.

Taslak içerik türüne özel workflow: Hazırlık → Hukukî inceleme → Görüş toplama → Görüşlerin değerlendirilmesi ve revizyon → Onaylandı → Yayımlandı; ayrıca arşiv, geri dönüş ve aynı durumda kaydetme geçişleri. Görüş gerekmiyorsa incelemeden onaya geçilebilir.

Hukukî süreç durumu ile yayın moderasyonu farklı anlamlarını korur; otomatik eşitlenmez. Yayımlandı, taslak metninin yayımlandığını belirtir; mevzuatın yürürlüğe girdiğini belirtmez. Nihai mevzuat kaydı yetkili editör tarafından ayrıca oluşturulur ve ilişkilendirilir. Alt madde ve görüşler mevcut ortak editoryal workflow'da kalır. Üç türün varsayılan revizyon oluşturma ayarı zaten açıktı.

## Erişim ve kullanım

- Editör çalışma alanı: `/ayb/admin/content/mevzuat-taslaklari`.
- `/ayb/tr/mevzuat-taslaklari`
- `/ayb/tr/taslak-maddeleri`
- `/ayb/tr/taslak-gorusleri`
- `/ayb/tr/node/add/mevzuat_taslagi`

Yönetim Views'leri `administer ayb legal information` izniyle sınırlandırılır. Taslak sayfasından Madde ekle / Görüş ekle bağlantısı üst taslağı önceden doldurur. Her blok ilk 20 kaydı gösterir; tüm kayıtlar sayfalı yönetim listesine bağlanır.

Görüş sayfaları hukukî bilgi veya düğüm yönetimi yetkisi gerektirir. Anonim/açık yayın tercihi tek başına kamuya erişim açmaz; bu sürümde görüşler iç çalışma kaydıdır. Media dosyasının fiziksel indirme adresi için ayrı erişim politikası bu değişikliğin konusu değildir.

## Testler ve sınırlar

`tests/integration/draft.php` gerçek Entity::validate() ile kaydetmeden önce doğrulama yapar. 29 kontrol geçti: gerçek form, koşullu seçici, yayımlanmamış taslağın yönetim View'inde bulunması, yeni mevzuat, eksik mevzuat/ters tarih hataları, ilişkiler, gerekçeli görüş kararı, görüşe anonim erişimin reddi, inceleme/görüş/revizyon/onay/yayım, üç View sorgusu, node render, bölüm CSS sınıfları, metin farkı, ilgili kayıtlar, İngilizce çeviri kaydı ve ortak alanlar. Test düğümleri ve revizyonları finally içinde silindi.

Masaüstü 1440px ve mobil 390px görüntüleri gerçek Drupal render çıktısından üretilen bağımsız önizlemede incelendi; bu görsel kontrol navbar/footer veya tarayıcıdan form gönderimi testi değildir. Form API ayrıca test edildi. İlk CLI form testindeki Book uyarıları, route hazırlama adımı testte tamamlandıktan sonra tekrar oluşmadı. Önceki yanıtlardaki Autosave uyumsuzluğu teşhisi bu incelemede doğrulanmadı; yetkili kullanıcı ve doğru form hazırlığıyla form oluşturuldu.

Fark gösterimi Drupal çekirdeğinin Diff bileşeniyle mevcut ve önerilen düz metin arasında satır bazındadır. HTML biçim farklarını veya rastgele iki tarihî revizyonu karşılaştırmaz. Toplam 200 KB üzerindeki metinlerde madde bazında karşılaştırma önerilir. LEOS'un eşzamanlı ortak düzenleme, Akoma Ntoso XML ve metin içi öneri kabul/ret editörü ayrı ürün kapsamıdır; uygulanmadı.

## Değişen dosyalar

- Mevcut uygulama kodu `ayb_mevzuat_calisma` modülüne taşındı: `ayb_mevzuat_calisma.module`, `includes/draft.inc`, route/menu/permission/library tanımları, çalışma alanı controller/form, validation constraint ve CSS.
- `ayb_mevzuat_calisma.install`: çalışma modeli alanının idempotent kurulumu ve editör izinleri.
- `ayb_media_normalizasyon.module`: yalnızca medya normalizasyonu, sözleşme çeviri formu ve genel metin karşılaştırma davranışları kaldı.
- Önceden uygulanmış `ayb_media_normalizasyon` update hook'ları Drupal'ın modül bazlı şema geçmişini korumak için tarihsel kayıt olarak bırakıldı; yeni taslak davranışları bu modüle eklenmemelidir.
- Entegrasyon kontrol dosyası `ayb_mevzuat_calisma/tests/integration/draft.php` konumuna taşındı.

İşlem öncesi yedek: `/tmp/ayb-before-draft-upgrade-20261026.sql.gz`. /tmp geçicidir. Üç taslak türünde işlem öncesi kayıt yoktu; üretim içeriği aktarılmadı veya silinmedi. Elle görüş sayısı alanı depolaması korundu, formdan kaldırıldı.
