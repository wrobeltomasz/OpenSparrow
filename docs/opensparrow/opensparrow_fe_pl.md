# OpenSparrow - Dokumentacja Funkcji Frontendu

**Wersja:** 2.9+  
**Język:** Polski  
**Przeznaczenie:** Dokumentacja dla użytkowników i modeli AI. Opisuje wszystkie funkcje dostępne w interfejsie użytkownika (frontend) platformy OpenSparrow - konfigurowalnego systemu CRUD/dashboard na PostgreSQL.

---

## Spis treści

1. [Architektura i role użytkowników](#1-architektura-i-role-użytkowników)
2. [Logowanie i sesja](#2-logowanie-i-sesja)
3. [Menu boczne i nawigacja](#3-menu-boczne-i-nawigacja)
4. [Siatka danych (Grid)](#4-siatka-danych-grid)
   - 4.1 [Wyświetlanie kolumn](#41-wyświetlanie-kolumn)
   - 4.2 [Sortowanie](#42-sortowanie)
   - 4.3 [Filtrowanie](#43-filtrowanie)
   - 4.4 [Wyszukiwanie globalne](#44-wyszukiwanie-globalne)
   - 4.5 [Paginacja](#45-paginacja)
   - 4.6 [Edycja inline](#46-edycja-inline)
   - 4.7 [Akcje na wierszu](#47-akcje-na-wierszu)
   - 4.8 [Zaznaczanie wierszy](#48-zaznaczanie-wierszy)
   - 4.9 [Tooltip wiersza](#49-tooltip-wiersza)
   - 4.10 [Podtabele (Subtables)](#410-podtabele-subtables)
   - 4.11 [Komentarze do rekordów](#411-komentarze-do-rekordów)
5. [Nawigacja klawiaturą w siatce](#5-nawigacja-klawiaturą-w-siatce)
6. [Masowe operacje (Mass Edit)](#6-masowe-operacje-mass-edit)
7. [Formularz tworzenia rekordu (Create)](#7-formularz-tworzenia-rekordu-create)
8. [Formularz edycji rekordu (Edit)](#8-formularz-edycji-rekordu-edit)
9. [Kalendarz](#9-kalendarz)
10. [Dashboard](#10-dashboard)
11. [Menedżer plików](#11-menedżer-plików)
12. [RAG - czat z dokumentami](#12-rag---czat-z-dokumentami)
13. [Widoki (Views)](#13-widoki-views)
14. [Workflows - kreator kroków](#14-workflows---kreator-kroków)
15. [Czyszczenie danych (Find & Replace)](#15-czyszczenie-danych-find--replace)
16. [Import CSV](#16-import-csv)
17. [Panel admina](#17-panel-admina)
    - 17.1 [Schemat (Tables & Columns)](#171-schemat-tables--columns)
    - 17.2 [Dashboard](#172-dashboard-admin)
    - 17.3 [Kalendarz](#173-kalendarz-admin)
    - 17.4 [Baza danych](#174-baza-danych)
    - 17.5 [Użytkownicy](#175-użytkownicy)
    - 17.6 [Bezpieczeństwo](#176-bezpieczeństwo)
    - 17.7 [Zdrowie systemu (Health)](#177-zdrowie-systemu-health)
    - 17.8 [Migracje](#178-migracje)
    - 17.9 [Wydajność (Performance)](#179-wydajność-performance)
    - 17.10 [Cron / Zaplanowane zadania](#1710-cron--zaplanowane-zadania)
    - 17.11 [M2M (wiele-do-wielu)](#1711-m2m-wiele-do-wielu)
    - 17.12 [ERD (diagram encji)](#1712-erd-diagram-encji)
    - 17.13 [RAG baza wiedzy](#1713-rag-baza-wiedzy-admin)
    - 17.14 [Automatyzacje](#1714-automatyzacje)
    - 17.15 [Log audytu](#1715-log-audytu)
    - 17.16 [Backup / Restore](#1716-backup--restore)
    - 17.17 [Ustawienia globalne](#1717-ustawienia-globalne)
    - 17.18 [Dokumentacja wbudowana (Docs)](#1718-dokumentacja-wbudowana-docs)
    - 17.19 [Edytor Workflows](#1719-edytor-workflows)
    - 17.20 [Edytor Widoków](#1720-edytor-widoków)
    - 17.21 [Systemy demo](#1721-systemy-demo)
    - 17.22 [Zewnętrzne bazy danych (MySQL Gateway)](#1722-zewnętrzne-bazy-danych-mysql-gateway)
    - 17.23 [Anonimizacja danych](#1723-anonimizacja-danych)
18. [Responsywność i mobile](#18-responsywność-i-mobile)
19. [Powiadomienia i feedback](#19-powiadomienia-i-feedback)
20. [Internacjonalizacja (i18n)](#20-internacjonalizacja-i18n)
21. [Eksport i import danych](#21-eksport-i-import-danych)
22. [Dostępność (WCAG 2.1)](#22-dostępność-wcag-21)
23. [Przypadki użycia biznesowego](#23-przypadki-użycia-biznesowego)
24. [Tablica (Board / Kanban)](#24-tablica-board--kanban)

---

## 1. Architektura i role użytkowników

OpenSparrow to platforma schema-driven: administrator definiuje tabele, kolumny i relacje w pliku konfiguracyjnym `config/schema.json`, a system generuje automatycznie interfejs CRUD, dashboard, kalendarz i inne widoki.

### Role użytkowników

| Rola | Uprawnienia |
|------|-------------|
| **Admin** | Pełny dostęp do panelu admina i frontendu. Zarządza użytkownikami, schematem, migracjami. |
| **Editor** | Pełny CRUD na froncie (tworzenie, edycja, usuwanie rekordów). Brak dostępu do `/admin/`. |
| **Viewer** | Wyłącznie odczyt. Przyciski zapisu niewidoczne. Formularze w trybie readonly. |

### Adresy URL frontendu

| Adres | Opis |
|-------|------|
| `index.php?table=<nazwa>` | Siatka danych tabeli |
| `create.php?table=<nazwa>` | Formularz tworzenia rekordu |
| `edit.php?table=<nazwa>&id=<id>` | Formularz edycji rekordu |
| `calendar.php` | Widok kalendarza |
| `dashboard.php` | Dashboard z widgetami |
| `files.php` | Menedżer plików |
| `rag.php` | Czat z bazą wiedzy (RAG) |
| `views.php` | Zapisane widoki (filtry) |
| `/admin/` | Panel administracyjny |

---

## 2. Logowanie i sesja

- Adres: `login.php`
- Pola: nazwa użytkownika, hasło
- Błąd logowania wyświetlany w formularzu
- Sesja wygasa po 8 godzinach (SESSION_MAX_LIFETIME)
- Regeneracja ID sesji przy każdym logowaniu
- Token CSRF weryfikowany przy wszystkich żądaniach POST/PATCH/DELETE
- Ciasteczka: HttpOnly, SameSite=Strict
- Weryfikacja User-Agent hash (ochrona przed przechwyceniem sesji)

---

## 3. Menu boczne i nawigacja

- Stałe lewe menu z listą tabel (z ikonami PNG z `assets/icons/`)
- Aktywna tabela wyróżniona kolorem tła
- Kliknięcie tabeli przełącza na `index.php?table=<nazwa>`
- Link "Workflows" widoczny jeśli skonfigurowane
- Sekcja "Widoki" jeśli istnieją zapisane widoki
- Na mobile: zwija się pod przycisk hamburger, wysuwa jako off-canvas drawer

**Menu użytkownika** (prawy górny róg):
- Wyświetla aktualną nazwę użytkownika
- Przycisk wylogowania

---

## 4. Siatka danych (Grid)

Główny widok: `index.php?table=<nazwa_tabeli>`. Wyświetla rekordy tabeli jako tabelę z obsługą sortowania, filtrowania, edycji i operacji masowych.

### 4.1 Wyświetlanie kolumn

- Kolumny mają nazwy wyświetlane (`display_name`) ustawiane przez admina
- Kolumny wirtualne (obliczane) oznaczone plakietką `f(x)` w nagłówku
- Najechanie na nagłówek kolumny pokazuje opis (tooltip z pola `description`)
- Kolumny `enum` wyświetlają kolorowe plakietki (kolory z `enum_colors` w schemacie)
- Kolumny FK (klucze obce) wyświetlają wartość z kolumny `display` zamiast surowego ID
- Kolumny M2M (wiele-do-wielu) wyświetlają listę chipów z powiązanymi wartościami
- Identyfikatory rekordów (ID) domyślnie ukryte

### 4.2 Sortowanie

- Kliknięcie nagłówka kolumny przełącza: rosnąco → malejąco → brak sortowania
- Strzałka w nagłówku wskazuje aktualny kierunek (rosnąco / malejąco)
- Sortowanie działa na jednej kolumnie naraz
- Domyślna kolumna i kierunek sortowania konfigurowane przez admina (`default_sort` w schemacie)

### 4.3 Filtrowanie

Otwieranie filtrów: przycisk "Filtruj" lub ikona filtra.

Typ filtru zależy od typu kolumny:

| Typ kolumny | Dostępny filtr |
|-------------|---------------|
| Tekst/string | Pole wyszukiwania (dopasowanie częściowe, case-insensitive) |
| Enum / FK | Lista rozwijana z dostępnymi wartościami |
| Data | Pola "Od" i "Do" (zakres dat) |
| Liczba | Pola "Min" i "Max" |
| Boolean | Lista: Tak / Nie / Wszystkie |

**Zarządzanie filtrami:**
- Aktywne filtry wyświetlane jako chipy (plakietki) nad siatką
- Kliknięcie X na chipsie usuwa filtr
- Przycisk "Wyczyść filtry" usuwa wszystkie aktywne filtry naraz
- Filtry można przekazać przez URL: `?filter_col=<kolumna>&filter_val=<wartość>`

### 4.4 Wyszukiwanie globalne

- Pole tekstowe `#globalSearch` nad siatką
- Przeszukuje wszystkie widoczne kolumny jednocześnie
- Case-insensitive, dopasowanie częściowe
- Skrót: `Ctrl+F` ustawia fokus w polu i podświetla dopasowania w komórkach

### 4.5 Paginacja

- Pasek paginacji na dole siatki: "Wiersze X-Y z Z łącznie"
- Przyciski: Poprzednia / Następna strona, Pierwsza / Ostatnia strona
- Lista rozwijana "Wierszy na stronę": wartości 25 / 50 / 100
- Wybór użytkownika zapisywany w `localStorage` (persists między sesjami)
- Domyślna wartość ustawiana przez admina w `schema.json` (`default_page_size`)

### 4.6 Edycja inline

- Kliknięcie komórki (rola Editor/Admin) otwiera edycję bezpośrednio w komórce (`contentEditable`)
- Enter lub kliknięcie poza komórką: zapisuje zmianę przez API
- Escape: anuluje edycję bez zapisu
- F2: wchodzi w tryb edycji zaznaczonej komórki
- Sukces: komórka miga zielono; błąd: miga czerwono
- Pola readonly nieaktywne (ikona kłódki, brak edycji)

### 4.7 Akcje na wierszu

Menu akcji (ikona trzech kropek lub przyciski przy wierszu):
- **Edytuj**: otwiera `edit.php?table=<tabela>&id=<id>`
- **Duplikuj**: tworzy kopię rekordu z nowym ID; jeśli naruszenie unikalności - komunikat błędu
- **Usuń**: dialog potwierdzenia, po akceptacji usuwa rekord i zapisuje log audytu

### 4.8 Zaznaczanie wierszy

- Checkbox w pierwszej kolumnie per wiersz
- Checkbox w nagłówku: zaznacza/odznacza wszystkie widoczne wiersze
- Zaznaczenie >= 1 wiersza: pokazuje pływający pasek masowych operacji (sekcja 6)

### 4.9 Tooltip wiersza

- Najechanie kursorem na komórkę: floating tooltip z pełnymi danymi wiersza
- Tooltip zawiera etykiety wszystkich pól i ich wartości

### 4.10 Podtabele (Subtables)

- Jeśli tabela ma powiązane podtabele (FK back-reference), przy wierszu pojawia się strzałka rozwijania
- Kliknięcie strzałki: mini-siatka z powiązanymi rekordami wewnątrz wiersza
- Podtabela konfigurowana przez admina w edytorze schematu

### 4.11 Komentarze do rekordów

- Badge z liczbą komentarzy przy wierszu (kliknięcie otwiera `edit.php`, zakładka Komentarze)
- W zakładce Komentarze:
  - Wątek komentarzy: autor, timestamp, treść
  - Formatowanie: `**pogrubienie**`, `*kursywa*`
  - URL w treści: automatycznie zamieniane na klikalne linki
  - Usuwanie: własne komentarze oraz (admin) cudze; usunięte zastępowane placeholderem
  - Automatyczne odświeżanie co 15 sekund
  - Dodawanie: pole tekstowe + przycisk "Dodaj"

---

## 5. Nawigacja klawiaturą w siatce

Wszystkie skróty działają gdy fokus jest w obszarze siatki.

### Poruszanie się

| Skrót | Akcja |
|-------|-------|
| Strzałki (góra/dół/lewo/prawo) | Przesuwanie fokusu między komórkami |
| Tab / Shift+Tab | Następna / poprzednia komórka |
| Home | Pierwsza komórka w wierszu |
| End | Ostatnia komórka w wierszu |
| Ctrl+Home | Pierwsza komórka w całej siatce |
| Ctrl+End | Ostatnia komórka w całej siatce |
| PageUp | Przeskok o 10 wierszy w górę |
| PageDown | Przeskok o 10 wierszy w dół |

### Zaznaczanie

| Skrót | Akcja |
|-------|-------|
| Shift+Strzałka | Rozszerza zaznaczenie (prostokąt wielu komórek) |
| Ctrl+A | Zaznacza wszystkie widoczne komórki |

### Edycja i schowek

| Skrót | Akcja |
|-------|-------|
| Enter lub F2 | Wchodzi w tryb edycji komórki |
| Escape | Wychodzi z trybu edycji (bez zapisu) |
| Ctrl+C | Kopiuje zaznaczone komórki (kolumny: tabulator, wiersze: nowa linia) |
| Ctrl+V | Wkleja ze schowka do edytowanej komórki |
| Ctrl+Z | Cofnięcie w edytowanej komórce |

### Globalne

| Skrót | Akcja |
|-------|-------|
| Ctrl+F | Fokus w polu wyszukiwania globalnego + podświetlenie dopasowań |
| Ctrl (przytrzymaj ~2 sek.) | Wyświetla modal z tabelą wszystkich skrótów klawiszowych |

**Overlay pomocy**: modal z listą skrótów. Zamykany przez Escape lub kliknięcie poza modalem. Dostępny też przez przycisk "?" w toolbarze.

---

## 6. Masowe operacje (Mass Edit)

Aktywowane przez zaznaczenie >= 1 wiersza checkboxami w siatce.

### Pływający pasek zaznaczenia

- Pojawia się na górze ekranu (sticky)
- Pokazuje: "N wierszy zaznaczonych"
- Znika po odznaczeniu wszystkich wierszy
- Przyciski: Edytuj masowo / Duplikuj / Usuń / Właściciel / Eksportuj / Odznacz wszystkie

### Panel masowej edycji

- Wysuwa się z prawej strony (drawer)
- Pole wejściowe dla każdej edytowalnej kolumny (pominięte: wirtualne, plikowe, M2M, ID)
- Puste pole = kolumna pominięta, wypełnione pole = kolumna zaktualizowana we wszystkich zaznaczonych rekordach
- Przycisk "Zastosuj" nieaktywny do czasu wypełnienia jakiegoś pola
- Komunikat sukcesu/błędu po operacji

### Masowe duplikowanie

- Tworzy kopie zaznaczonych wierszy z nowymi ID
- Zachowuje wszystkie wartości pól
- Dialog potwierdzenia przed wykonaniem
- Błąd przy naruszeniu unikalności z informacją o problematycznym rekordzie

### Masowe usuwanie

- Dialog potwierdzenia: "Czy na pewno usunąć N wierszy?"
- Zapisuje wpisy audytu w tabelach `*_log`
- Siatka odświeżana po sukcesie

### Masowa zmiana właściciela

- Lista rozwijana z aktywnymi użytkownikami systemu
- Aktualizuje wpisy w tabeli `spw_record_owners` (flaga `is_current`)

### Eksport zaznaczonych

- Eksportuje tylko zaznaczone wiersze do CSV
- Nazwa pliku: `<tabela>_<timestamp>.csv`
- Pobieranie bezpośrednio do przeglądarki użytkownika

---

## 7. Formularz tworzenia rekordu (Create)

Adres: `create.php?table=<nazwa_tabeli>`  
Otwierany z przycisku "+ Nowy rekord" w toolbarze lub FAB na urządzeniach mobilnych.

### Typy pól formularza

| Typ pola | Kontrolka |
|----------|-----------|
| Tekst / String | Pole tekstowe (input lub textarea) |
| Liczba całkowita / dziesiętna | Pole numeryczne |
| Boolean | Checkbox |
| Data | Date picker |
| Timestamp / DateTime | Date-time picker |
| Enum | Lista rozwijana z kolorowymi opcjami (kolory z `enum_colors`) |
| FK (klucz obcy) | Pole z autouzupełnianiem (datalist) - wpisz fragment, wybierz z listy |
| M2M (wiele-do-wielu) | Lista checkboxów z dostępnymi powiązanymi rekordami |
| Plik | Obszar upload (drag & drop lub kliknięcie "Przeglądaj") |
| Wirtualna | Brak (pomijana w formularzach) |

- Wymagane pola oznaczone czerwoną gwiazdką `*` przy etykiecie
- Walidacja regex: jeśli skonfigurowana w schemacie, pole sprawdzane przed zapisem z niestandardowym komunikatem błędu
- Pola readonly pomijane w formularzu

### Prefillowanie z URL

Pole FK można wstępnie wypełnić parametrem URL: `create.php?table=X&fk_column=123`  
Pole z prefillem jest zablokowane (readonly) z podaną wartością.  
Przydatne przy tworzeniu rekordów z poziomu powiązanej tabeli (np. "Nowe zamówienie dla klienta X").

### Zapis

- Przycisk "Zapisz nowy rekord" (zablokowany dla roli Viewer)
- Sukces: przekierowanie na siatkę lub komunikat sukcesu
- Błąd: wyświetlany w obszarze formularza

---

## 8. Formularz edycji rekordu (Edit)

Adres: `edit.php?table=<nazwa_tabeli>&id=<id>`  
Otwierany z akcji "Edytuj" w siatce lub bezpośrednio przez URL.

### Zakładki

| Zakładka | Zawartość |
|----------|-----------|
| Główna (Main) | Wszystkie edytowalne pola rekordu (analogicznie jak Create) |
| Podtabele | Jedna zakładka per FK back-reference (np. "Zamówienia" w karcie Klienta) |
| Pliki | Pliki załączone do rekordu |
| Komentarze | Wątek komentarzy (szczegóły w sekcji 4.11) |

### Zakładka Pliki (w Edit)

- Lista plików: nazwa, rozmiar, data uploadu, przycisk pobierania, przycisk usunięcia
- Obszar upload: drag & drop lub kliknięcie "Przeglądaj"

### Zakładka Podtabele

- Mini-siatka z powiązanymi rekordami
- Możliwość dodawania i usuwania rekordów podtabeli bez opuszczania formularza głównego

### Panel właściciela

- Prawy sidebar pokazuje aktualnego właściciela rekordu
- Przycisk "Zmień właściciela": lista rozwijana aktywnych użytkowników
- Aktualizuje `spw_record_owners` (flaga `is_current`)

### Zapis

- "Zapisz i wyjdź": zapisuje, przekierowuje na siatkę
- "Zapisz i zostań": zapisuje, pozostaje na formularzu
- Sukces: komunikat "Zapisano!" przy przekierowaniu z `?saved=1`
- Błędy wyświetlane pod formularzem

---

## 9. Kalendarz

Adres: `calendar.php`  
Widok miesięczny rekordów z kolumną daty. Umożliwia przeglądanie i przeciąganie zdarzeń.

### Nawigacja

- Tytuł "Miesiąc Rok" z przyciskami `<` i `>` do zmiany miesiąca
- Aktualny dzień wyróżniony kolorem tła
- Dni spoza bieżącego miesiąca wyszarzone
- Tydzień: poniedziałek - niedziela

### Zdarzenia

- Zdarzenia jako kolorowe chipy w komórkach dni
- Chip: opcjonalna ikona (PNG z `assets/icons/`) + tytuł zdarzenia (z konfigurowanej kolumny)
- Wiele zdarzeń tego samego dnia ustawia się pionowo
- Kolor zdarzenia z konfiguracji admina (`enum_colors` lub stały hex)

### Interakcje ze zdarzeniami

| Akcja | Efekt |
|-------|-------|
| Najechanie kursorem | Floating tooltip z pełnymi danymi rekordu |
| Kliknięcie chipu | Otwiera `edit.php` dla tego rekordu |
| Przeciągnięcie chipu na inny dzień | Zmienia datę rekordu przez API |

### Przeciąganie zdarzeń (Drag & Drop)

- Podczas przeciągania: docelowa komórka wyróżniana animowaną ramką
- Optymistyczny UI: zdarzenie przesuwa się natychmiast w widoku
- Jeśli zapis API się nie powiedzie: zdarzenie automatycznie cofa się do poprzedniej daty
- Po sukcesie: pole daty (`date_column`) rekordu zaktualizowane w bazie

### Konfiguracja przez admina

Panel admina → zakładka Kalendarz:
- Wybór tabeli z danymi
- Wybór kolumny daty
- Szablon tytułu (która kolumna = tytuł chipu zdarzenia)
- Reguły kolorowania
- Opcjonalna ikona

---

## 10. Dashboard

Adres: `dashboard.php`  
Siatka 3-kolumnowa widgetów z zagregowanymi danymi. Konfigurowana przez admina.

### Typy widgetów

| Typ | Opis |
|-----|------|
| KPI Card | Duża liczba z opcjonalnym wskaźnikiem trendu i warunkowym kolorowaniem |
| Stat Card | Tytuł metryki + wartość z kolorowym kodowaniem zakresu |
| Bar Chart | Wykres słupkowy pionowy z etykietami osi X/Y i legendą |
| Pie Chart | Wykres kołowy z kolorowymi segmentami i legendą |
| List Widget | Tabela wielokolumnowa rekordów z linkami drill-down |

### Interakcje

- **Drill-down**: kliknięcie wiersza/wartości/segmentu w widgecie przekierowuje na siatkę z zastosowanym filtrem wybranej wartości
- Globalny filtr daty (jeśli skonfigurowany) wpływa na wszystkie widgety jednocześnie

### Widgety warunkowe

Widget może być ukryty lub pokazany na podstawie warunków konfigurowanych przez admina (jeśli kolumna X = wartość Y, pokaż widget).

---

## 11. Menedżer plików

Adres: `files.php`  
Centralny widok zarządzania plikami (niezależny od rekordów).

### Upload

Pola formularza:
- Plik (kliknięcie lub drag & drop)
- Opcjonalna wyświetlana nazwa
- Opcjonalne tagi (po przecinku, np. "faktura, 2024")
- Docelowa tabela (do której tabeli należy plik)
- Docelowy rekord (lista rekordów po wyborze tabeli)
- Przycisk Upload + pasek postępu + komunikat statusu

### Przeglądarka plików

- Widok tabeli: nazwa, rozmiar, data uploadu, tagi, akcje
- Filtrowanie po tabeli / rekordzie
- Wyszukiwanie po nazwie lub tagu
- Kliknięcie nazwy = pobieranie pliku
- Przycisk usunięcia przy każdym pliku

---

## 12. RAG - czat z dokumentami

Adres: `rag.php`  
Interfejs czatu do zadawania pytań o zaindeksowane dokumenty (Retrieval Augmented Generation z lokalnym Ollama).

### Interfejs

- Lewy sidebar: checkboxy do filtrowania dokumentów po tagach (logika OR - odpowiedź z dowolnego zaznaczonego tagu)
- Obszar konwersacji: przewijalny wątek wiadomości
- Pole wejściowe: pytanie tekstowe + przycisk "Wyślij"
- Przycisk "Wyczyść historię": usuwa wiadomości z widoku (nie z bazy)

### Wiadomości

- Wiadomości użytkownika: wyrównane do prawej, jasne tło
- Odpowiedzi AI: wyrównane do lewej, ciemniejsze tło
- Stan oczekiwania: placeholder "Myślę..." podczas ładowania
- Poniżej odpowiedzi: chipy "Źródło: `<nazwa_pliku>`" jeśli odpowiedź cytuje dokumenty
- Błąd API: czerwony komunikat

### Zachowanie

- Wysłanie: Enter lub kliknięcie "Wyślij"
- Przycisk Wyślij nieaktywny podczas ładowania (brak duplikatów)
- Pole pytania czyszczone po wysłaniu
- Automatyczne przewijanie do najnowszej wiadomości
- Zaznaczone tagi filtrów zawężają zakres przeszukiwanych dokumentów

### Panel "Zapytaj AI" (siatka)

Wysuwany panel asystenta dostępny w aplikacji (ikona czatu lub pozycja w menu awatara). Korzysta z tej samej bazy wiedzy co `rag.php`, ale wszystkie źródła kontekstu wybiera się jawnie przez checkboxy:

- Pasek kontekstu na górze: "Widok: `<tabela>`" wskazuje aktualnie przeglądaną tabelę
- Checkbox "Dane z bieżącej tabeli": dołącza wiersze widocznej siatki do zapytania — wyłącznie gdy zaznaczony (nigdy automatycznie). Pojawia się tylko, gdy na stronie jest siatka z danymi
- Checkboxy tagów: każdy tag dokumentu ma własny checkbox; domyślnie wszystkie odznaczone — dokumenty trafiają do kontekstu tylko po świadomym zaznaczeniu
- Walidacja przed wysłaniem: jeśli nie zaznaczono żadnego checkboxa (ani tagu, ani danych tabeli), zapytanie nie jest wysyłane do modelu — pojawia się komunikat z prośbą o zaznaczenie co najmniej jednego źródła
- Odpowiedzi, chipy źródeł i sugerowane pytania renderowane tak samo jak w czacie `rag.php`

---

## 13. Widoki (Views)

Adres: `views.php`  
Zapisane konfiguracje filtrów i układu kolumn.

- Lista kart widoków z nazwą i opisem
- Kliknięcie widoku: ładuje go (stosuje zapisane filtry, ewentualnie kolejność kolumn)
- Tworzenie / edycja / usuwanie widoków dostępne dla roli Editor i Admin

### Źródło danych: PostgreSQL i MySQL

Edytor Widoków w panelu administracyjnym (Admin › Views) ma dwie zakładki, analogicznie do sekcji "Schema":

- **PostgreSQL Views** — wykrywa i konfiguruje widoki z bazy PostgreSQL
- **MySQL Views** — wykrywa i konfiguruje widoki z zewnętrznej bazy MySQL (przez MySQL Gateway)

Każda zakładka ma własny przycisk synchronizacji ("↻ Sync PostgreSQL Views" / "↻ Sync MySQL Views"), który pobiera listę widoków i metadane kolumn z odpowiedniej bazy. Źródło każdego widoku jest zapisywane w `config/views.json` (pole `source`), dzięki czemu drill-down, reguły kolorów i agregacje działają identycznie niezależnie od bazy. Widoki MySQL są tylko do odczytu (jak natywne widoki SQL).

---

## 14. Workflows - kreator kroków

Dostępny przez link "Workflows" w menu bocznym.

### Lista Workflows

- Siatka kart (nazwa, opis, liczba kroków, opcjonalna ikona)
- Kliknięcie karty uruchamia workflow

### Wykonywanie kroków

- Pasek postępu na górze ekranu ("Krok X z Y")
- Tytuł bieżącego kroku
- Pola formularza specyficzne dla kroku (FK select, pola tekstowe itd.)
- Tryb wielu rekordów: przycisk "Dodaj kolejny wiersz" dla powtarzalnych kroków

Przyciski nawigacji:

| Przycisk | Akcja |
|----------|-------|
| Zapisz i Dalej | Zapisuje krok, przechodzi do następnego |
| Zapisz i Wróć | Zapisuje krok, przechodzi do poprzedniego |
| Zapisz i Wyjdź | Zapisuje krok, zamyka workflow |
| Zapisz i Dodaj kolejny | Zapisuje, dodaje kolejny rekord dla tego samego kroku |

- Błędy walidacji wyświetlane per krok

---

## 15. Czyszczenie danych (Find & Replace)

Dostępny przez przycisk "Wyczyść dane" / "Find & Replace" w toolbarze siatki.

### Panel

Wysuwa się z prawej strony (drawer). Pola konfiguracji:
- Wybór kolumny (dropdown ze wszystkimi kolumnami tabeli)
- Pole "Znajdź" (tekst lub wyrażenie regularne)
- Pole "Zamień na" (tekst lub regex z grupami przechwytywania `$1`, `$2`)

Opcje (checkboxy):
- Bez rozróżnienia wielkości liter (case-insensitive)
- Dopasowanie całego słowa
- Ignoruj akcenty (np. "a" znajdzie "ą", "e" znajdzie "ę")

### Podgląd i zastosowanie

- Przycisk "Podgląd": pokazuje do 20 dopasowań z wizualizacją zmian
  - Stary tekst: ~~przekreślony~~ (element `<del>`)
  - Nowy tekst: podkreślony (element `<ins>`)
- Licznik dopasowań: "N dopasowań znaleziono"
- Przycisk "Zastosuj": nieaktywny do czasu uruchomienia podglądu
- Stosuje zamianę we WSZYSTKICH dopasowanych rekordach tabeli
- Komunikat statusu: ładowanie / sukces / błąd

---

## 16. Import CSV

Dostępny w panelu admina → zakładka CSV Import.

### Kreator 3-etapowy

**Krok 1 - Wybór tabeli i upload:**
- Dropdown z listą tabel
- Obszar drag & drop lub kliknięcie dla pliku CSV
- Podgląd liczby wierszy po wczytaniu

**Krok 2 - Mapowanie kolumn:**
- Nagłówki CSV wyświetlane po lewej
- Kolumny docelowe po prawej
- Przeciąganie nagłówków CSV do odpowiednich kolumn docelowych
- Podgląd przykładowych wierszy

**Krok 3 - Konfiguracja i wykonanie:**
- Opcja upsert: wybór kolumny unikalnej (aktualizuje zamiast duplikować)
- Pasek postępu importu
- Raport błędów per wiersz jeśli cokolwiek się nie powiedzie
- Podsumowanie: liczba rekordów dodanych / zaktualizowanych

---

## 17. Panel admina

Adres: `/admin/`  
Dostępny dla ról Admin i Editor.

### 17.1 Schemat (Tables & Columns)

Zarządzanie konfiguracją tabel i kolumn (`config/schema.json`).

**Ustawienia globalne:** domyślny rozmiar strony siatki, inne opcje widoczności.

**Edytor tabeli** (per tabela):
- Wyświetlana nazwa, ikona, flaga ukrycia (wyklucza z menu)
- Domyślne sortowanie: kolumna + kierunek
- Limit początkowego ładowania (klauzula `LIMIT`)

**Edytor kolumn** (per kolumna):

| Pole | Opis |
|------|------|
| Nazwa | Nazwa kolumny w bazie |
| Typ | string / int / enum / FK / M2M / plik / wirtualna itp. |
| Wyświetlana nazwa | Etykieta w UI |
| Opis | Tooltip w nagłówku siatki |
| Nullable | Czy dopuszcza null |
| Unikalna | Wymusza unikalność |
| Readonly | Tylko do odczytu w UI |
| Wartość domyślna | Default przy tworzeniu |
| Walidacja regex | Pattern + niestandardowy komunikat błędu |
| Opcje enum | Lista wartości + kolory |
| Konfiguracja FK | Tabela + kolumna referencyjna + kolumna wyświetlana |

- Zmiana kolejności kolumn: uchwyt drag-and-drop
- Konfiguracja podtabel (FK back-references)
- Konfiguracja M2M (wiele-do-wielu)

### 17.2 Dashboard (Admin)

- Dodaj widget: wybór typu (KPI / stat / wykres słupkowy / kołowy / lista)
- Konfiguracja widgetu: tytuł, tabela źródłowa, funkcja agregacji (SUM/COUNT/AVG), kolumny osi, grupowanie, warunki widoczności, szerokość/wysokość, paleta kolorów
- Przeciąganie widgetów: zmiana kolejności w siatce 3-kolumnowej
- Podgląd na żywo: dashboard odświeżany w czasie rzeczywistym podczas edycji

### 17.3 Kalendarz (Admin)

- Wybór tabeli i kolumny daty
- Szablon tytułu zdarzenia (która kolumna = tytuł)
- Reguły kolorowania (enum lub stały hex)
- Opcjonalna ikona (PNG z `assets/icons/`)

### 17.4 Baza danych

- Przeglądarka schematu PostgreSQL (widok i edycja)
- Kreator tworzenia tabeli
- Dokumentacja typów kolumn

### 17.5 Użytkownicy

- Lista użytkowników: ID, nazwa, status (aktywny/nieaktywny), rola
- Dropdown roli per użytkownik: admin / editor / viewer
- Przyciski dezaktywacji / aktywacji konta
- Dialog zmiany hasła per użytkownik (z paskiem siły hasła)
- Formularz dodania nowego użytkownika: nazwa + hasło + rola

### 17.6 Bezpieczeństwo

- Ustawienia CORS, HSTS, tryby CSP
- Opcjonalna lista dozwolonych IP (whitelist)
- Konfiguracja limitu czasu sesji
- Polityka haseł

### 17.7 Zdrowie systemu (Health)

- Status połączenia z bazą danych
- Analiza bloat tabel (szacunkowy % zmarnowanego miejsca)
- Doradca indeksów (brakujące indeksy)
- Log wolnych zapytań
- Status kopii zapasowych

### 17.8 Migracje

**Zakładka Migracje bazy danych:**
- Lista migracji systemu (pending / applied)
- Przycisk "Zastosuj oczekujące migracje"
- Status per migracja

**Zakładka Migracje wydania:**
- Czyszczenie konfiguracji na podstawie `config/migrations.json`
- Śledzenie usuniętych plików i kluczy konfiguracji między wersjami
- Ręczne zastosowanie dla upgradeów wersji

### 17.9 Wydajność (Performance)

6 sekcji diagnostycznych (każda z przyciskiem "Skanuj"):

| Sekcja | Zawartość |
|--------|-----------|
| Doradca indeksów | Brakujące indeksy z rekomendowanym SQL |
| Nieużywane indeksy | Kandydaci do usunięcia |
| Log wolnych zapytań | Zapytania przekraczające próg czasu |
| Bloat tabel | Szacunkowy % zmarnowanego miejsca per tabela |
| Zdrowie DB | Pula połączeń, cache hit rate |
| Ostrzeżenia schematu | Np. tabela bez klucza głównego |

Przyciski "Kopiuj SQL" dla rekomendowanych zapytań naprawczych.

### 17.10 Cron / Zaplanowane zadania

5 sekcji:
1. **Ręczne uruchomienie**: wyzwolenie crona powiadomień natychmiast
2. **Historia**: log uruchomień (status, output, czas)
3. **Statystyki**: średni czas wykonania, wskaźnik sukcesu, czas ostatniego uruchomienia
4. **Przewodnik konfiguracji**: instrukcje integracji z systemowym cronem serwera
5. **Czyszczenie logów**: usuwanie starych wpisów logów crona

### 17.11 M2M (wiele-do-wielu)

- Lista tabel z konfiguracją M2M
- Edytor per tabela: tabela FK lewa / prawa, nazwa tabeli łączącej (join table), kolumny wyświetlane
- Wizualny kreator M2M (visual table selector)

### 17.12 ERD (diagram encji)

- Wizualny diagram ER: wszystkie tabele + relacje FK jako strzałki
- Kliknięcie tabeli w diagramie: nawigacja do edytora schematu tej tabeli
- Kontrolki zoomu i przesuwania widoku

### 17.13 RAG baza wiedzy (Admin)

**Zakładka Upload plików:**
- Drag & drop pliki PDF lub tekstowe
- Opcjonalna podpowiedź językowa (dla dokumentów wielojęzycznych)
- Przypisanie tagów (po przecinku)
- Pasek postępu uploadu i indeksowania

**Zakładka Lista plików:**
- Lista zaindeksowanych plików: nazwa, rozmiar, tagi, data, akcje
- Usuwanie pliku, zmiana tagów, wyszukiwanie po nazwie lub tagu

**Zakładka Statystyki:**
- Łączna liczba plików i tokenów
- Statystyki per plik: tokeny, status indeksowania
- Historia zapytań: najczęstsze pytania, częstotliwość

### 17.14 Automatyzacje

Reguły automatycznego działania przy zdarzeniach tworzenia lub aktualizacji rekordów. Konfigurowane wyłącznie przez admina; wykonywane przez backend przy każdym pasującym zdarzeniu.

**Lista automatyzacji:** tabela skonfigurowanych reguł — nazwa, tabela, zdarzenie, status. Przyciski: Edit / History / Delete per reguła.

#### Wyzwalacze

| Zdarzenie | Kiedy |
|-----------|-------|
| `After create` | po INSERT rekordu |
| `After update` | po UPDATE rekordu (PATCH) |

#### Warunki (AND/OR, grupy zagnieżdżone)

Warunki zorganizowane w grupy z operatorem AND lub OR. Grupy można zagnieżdżać.

| Operator | Opis |
|----------|------|
| `equals` | wartość pola = wartość |
| `not equals` | wartość pola != wartość |
| `contains` | pole zawiera tekst |
| `not contains` | pole nie zawiera tekstu |
| `is empty` | pole puste / NULL |
| `is not empty` | pole niepuste |

Przycisk "+ Condition" dodaje warunek do grupy; "+ Group" tworzy podgrupę z własnym AND/OR.

#### Typy akcji

**1. Update fields on this record** — aktualizuje pola wyzwalającego rekordu.

| Wartość | Przykład |
|---------|---------|
| Literał | `nowy` |
| Zmienna użytkownika | `{{ current_user.id }}` |
| Wartość z rekordu | `{{ record.nazwa_pola }}` |

**2. Send notification** — wstawia powiadomienie do `spw_users_notifications`.

| Pole | Opis |
|------|------|
| User ID | ID odbiorcy — literał lub `{{ current_user.id }}` |
| Title | Treść powiadomienia (obsługuje zmienne) |
| Link | Opcjonalny URL (obsługuje zmienne) |

Jedno powiadomienie per (reguła, rekord, użytkownik, dzień) — duplikaty ignorowane.

**3. Create record in another table** — wstawia nowy rekord do wybranej tabeli.

- Wybór tabeli docelowej z listy tabel schematu
- Mapowanie pól: kolumna = wartość (obsługuje zmienne `{{ record.* }}`)

#### Historia uruchomień (Run History)

Przycisk "History" przy każdej regule otwiera panel historii. Tabela ostatnich 100 uruchomień:

| Kolumna | Opis |
|---------|------|
| Time | Czas wykonania |
| Table | Tabela wyzwalacza |
| Record | ID rekordu |
| Event | `create` / `update` |
| Status | `ok` / `error` / `skipped` |
| Error | Komunikat błędu (jeśli status = `error`) |

Status `skipped` = warunki nie zostały spełnione. Dane przechowywane w `spw_automation_runs`.

### 17.15 Log audytu

- Tabela wszystkich mutacji rekordów (`INSERT` / `UPDATE` / `DELETE`)
- Kolumny: timestamp, użytkownik, akcja, tabela, ID rekordu
- Filtrowanie po tabeli, użytkowniku, typie akcji
- Podgląd migawki rekordu przed i po zmianie

### 17.16 Backup / Restore

- Przycisk ręcznego tworzenia backupu (zrzut SQL)
- Lista backupów z timestampami
- Pobieranie backupu (plik `.sql`)
- Przywracanie z backupu (wymaga potwierdzenia)

### 17.17 Ustawienia globalne

- Ustawienia aplikacji: motyw, nazwa systemu
- Konfiguracja menu (widoczność, kolejność)
- Ustawienia powiadomień

### 17.18 Dokumentacja wbudowana (Docs)

Wbudowana dokumentacja systemu:
- Sekcje: Pierwsze kroki / Schemat / Funkcje siatki / Panel admina / Rozwiązywanie problemów
- Wyszukiwanie w dokumentacji

### 17.19 Edytor Workflows

- Lista workflows z kartami
- Konfiguracja per workflow: tytuł, opis, ikona
- Kreator kroków: dodaj / usuń / zmień kolejność kroków (drag-and-drop)
- Konfiguracja per krok: pola formularza, tabela docelowa, opcje multi-record

### 17.20 Edytor Widoków

- Lista zapisanych widoków
- Tworzenie nowego widoku: nazwa, opis, filtry, kolejność kolumn
- Edycja i usuwanie widoków

### 17.21 Systemy demo

Jednym kliknięciem załaduj przykładowe dane do testowania:

| System | Zawiera |
|--------|---------|
| CRM | Firmy, kontakty, szanse sprzedaży (deals), leady, produkty (M2M), oferty, faktury, aktywa; 10 widgetów dashboardu, 5 źródeł kalendarza z przypomnieniami dla instalującego admina, 2 workflows, 5 widoków, 3 automatyzacje (m.in. oferta zaakceptowana → automatyczna faktura robocza) |
| WMS | Magazyny, inwentarz, wysyłki |
| Tasks | Projekty, zadania, przypisania |

Przycisk "Reset" per system usuwa dane demo.

### 17.22 Zewnętrzne bazy danych (MySQL Gateway)

Zakładka **External Databases** (sekcja Data Management) umożliwia skonfigurowanie routingu zapytań bazy danych między PostgreSQL a MySQL na poziomie aplikacji PHP — bez zmian we frontendzie. Moduł implementuje wzorzec Factory Method w warstwie `includes/db/`.

**Status połączenia MySQL:** górna karta pokazuje skonfigurowane zmienne środowiskowe (`MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DB`, `MYSQL_USER`) oraz aktualny stan połączenia (etykieta Connected / Not connected / Not configured). Przycisk **Test connection** weryfikuje połączenie na żądanie.

**Konfiguracja zmiennych środowiskowych:** składana sekcja instrukcji pokazuje przykładowy fragment `docker-compose.override.yml` do ustawienia zmiennych `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DB`, `MYSQL_USER`, `MYSQL_PASSWORD`.

**Routing tabel MySQL:** lista nazw tabel kierowanych do MySQL zamiast PostgreSQL. Tabele spoza listy trafiają domyślnie do PostgreSQL. Konfiguracja przechowywana jest w `config/mysql_gateway.json`.

**Warstwa abstrakcji (`includes/db/`):**
- `DatabaseGatewayInterface` — kontrakt `fetchAll(string $table): array`.
- `PostgresGateway` — implementacja przez natywne funkcje `pg_*`.
- `MysqlGateway` — implementacja przez PDO.
- `DatabaseFactory::make($table, $pgConn, $mysqlPdo)` — wybiera implementację na podstawie listy tabel MySQL; akcja `mysql_preview` w `admin/api_fdw.php` demonstruje integrację.

### 17.23 Anonimizacja danych

Zakładka **Anonymization** (sekcja System) obsługuje automatyczną anonimizację danych osobowych zgodnie z wymogami RODO — prawdziwie zanonimizowane dane nie są danymi osobowymi i nie podlegają przepisom o ochronie danych UE. Moduł działa wyłącznie w panelu admina, cronie i bazie danych (brak wpływu na frontend).

**Zakładka Rules:** definiuje reguły anonimizacji — dla każdej reguły wybierz tabelę, kolumnę typu data/znacznik czasu oraz próg wieku (anonimizowane są tylko rekordy starsze niż N dni liczonych od tej kolumny), kolumnę z danymi osobowymi i wartość zastępczą (np. `***ANONYMIZED***`). Przycisk **Preview (dry run)** wykonuje próbny przebieg: zlicza, ile wierszy obejmie każda reguła, **bez modyfikowania danych** — pozwala zweryfikować konfigurację przed nieodwracalnym nadpisaniem. Reguły przechowywane w `config/anonymization.json`. Historia wykonań widoczna w sekcji Run History (tabela `spw_anonymization_log`).

**Raport wykonania (Report):** każdy przebieg generuje ustrukturyzowany raport JSON zapisywany w dedykowanej tabeli `spw_anonymization_report` (kolumna `report` typu `jsonb`, powiązana z przebiegiem przez `log_id`). Raport zawiera identyfikator zadania (`report_id`, np. `JOB-20260629-0042`), znacznik czasu UTC, podsumowanie wykonania (`execution_summary`: liczba przetworzonych reguł, liczba dotkniętych tabel, łączna liczba zanonimizowanych wierszy) oraz listę `details` z wpisem dla każdej reguły: tabela, kolumna, metoda (`static_replacement`), parametry (wartość zastępcza, kolumna daty, próg dni), informacja o nieodwracalności (`is_reversible: false`), liczba zmienionych wierszy oraz ocena ryzyka w stylu EDPB/RODO (`edpb_compliance`: ryzyko wyodrębnienia, powiązania i wnioskowania). W sekcji Run History (zakładka Rules) kolumna **Report** udostępnia przycisk **View** (rozwija sformatowany JSON pod wierszem) oraz **Download JSON** (pobranie raportu jako pliku `<report_id>.json`). Tryb próbny (Preview) nie generuje raportu — nie zapisuje wpisu w dzienniku.

**Zakładka Schedule:** włączenie/wyłączenie modułu, wybór częstotliwości (Manual / Daily / Weekly / Monthly) egzekwowanej przez sam skrypt crona. Przycisk **Run Now** uruchamia `cron/cron_anonymization.php` natychmiast przez panel admina. Sekcja Cron Setup Guide zawiera gotowe polecenia dla Linux/macOS (crontab), Windows (Task Scheduler) i Docker.

**Zakładka Suggestions:** przycisk **Scan Schema** przeszukuje `schema.json` pod kątem kolumn, których nazwy zawierają słowa kluczowe ze słownika. Znalezione kolumny wyświetlane są w tabeli; przycisk **+ Add Rule** dodaje regułę dla danej kolumny.

**Zakładka Dictionary:** lista słów kluczowych rozdzielona przecinkami (np. `PESEL, NIP, email, phone, address, imię, nazwisko`). Używana przez zakładkę Suggestions do dopasowania nazw kolumn (dopasowanie podciągu, bez rozróżniania wielkości liter). Sekcja Log Cleanup pozwala usunąć stare wpisy z `spw_anonymization_log` starsze niż N dni.

**Skrypt crona:** `cron/cron_anonymization.php` — tylko CLI. Sprawdza flagę `enabled`, egzekwuje okno częstotliwości (sprawdza ostatni udany run w tabeli logu), wykonuje `UPDATE` dla każdej reguły z użyciem `pg_ident()` dla identyfikatorów; warunek `WHERE` filtruje rekordy starsze niż próg dni względem wskazanej kolumny daty. Rejestruje każde wykonanie w `spw_anonymization_log` (status running → success/error, liczba przetworzonych reguł, liczba zanonimizowanych wierszy) oraz zapisuje ustrukturyzowany raport JSON (EDPB/RODO) w dedykowanej tabeli `spw_anonymization_report`. Tryb próbny (drugi argument `dry`, wywoływany przez przycisk **Preview**) uruchamia `SELECT COUNT(*)` z tym samym warunkiem `WHERE` zamiast `UPDATE` — nie zmienia danych i nie zapisuje wpisu w dzienniku.

---

## 18. Responsywność i mobile

### Funkcje mobilne

| Element | Zachowanie na mobile |
|---------|---------------------|
| Sidebar | Chowa się za przycisk hamburger (≡) |
| Off-canvas Drawer | Sidebar wysuwa się z lewej po kliknięciu menu |
| FAB (Floating Action Button) | Przycisk "+" do tworzenia rekordu (rola Editor/Admin) |
| Search Drawer | Wyszukiwanie globalne w wysuwany panel overlay |
| Touch targets | Powiększone cele dotykowe |
| Siatka | Poziome przewijanie na wąskich ekranach |

Testowane na: iPhone, iPad, desktop.  
Brak zewnętrznych CDN - wszystkie zasoby lokalne, działa offline.

---

## 19. Powiadomienia i feedback

### Toast Messages

| Typ | Kolor | Czas wyświetlania |
|-----|-------|-------------------|
| Sukces | Zielony | 3 sekundy |
| Błąd | Czerwony | 6 sekund |
| Info | Niebieski | 3 sekundy |

Pozycja: prawy dolny róg ekranu.

### Inline feedback (admin)

- Status pills bezpośrednio w panelu po akcji API
- Auto-zanikanie po kilku sekundach

### Dialogi potwierdzenia

- Wymagane przy operacjach destrukcyjnych (usuń, dezaktywuj, przywróć backup)
- Przyciski: Anuluj / OK

---

## 20. Internacjonalizacja (i18n)

- Pliki językowe: `languages/{locale}.json` (np. `en.json`, `pl.json`)
- Format klucza: `scope.key` (np. `grid.search_placeholder`, `form.add_new_record`)
- Dynamiczne ładowanie przy starcie strony
- Automatyczne formatowanie liczb i dat per locale

Przetłumaczalne elementy: etykiety przycisków, placeholdery, komunikaty błędów, dialogi potwierdzenia, teksty pomocy, nazwy pól (z `display_name` schematu), pozycje menu.

---

## 21. Eksport i import danych

### Eksport CSV

- Z paska masowych operacji: eksportuje zaznaczone wiersze
- Lub globalny eksport wszystkich widocznych wierszy z toolbaru
- Format: kolumny oddzielone tabulatorem, wiersze nową linią
- Nazwa pliku: `<tabela>_<timestamp>.csv`

### Import CSV

Opisany szczegółowo w sekcji 16. Kreator 3-etapowy z mapowaniem kolumn i opcją upsert.

---

## 22. Dostępność (WCAG 2.1)

- ARIA labels na polach formularzy, przyciskach i regionach
- Live region dla ogłoszeń nawigacji klawiaturą (czytniki ekranu)
- Pełna nawigacja klawiaturą w siatce bez myszy (szczegóły w sekcji 5)
- Widoczne wskaźniki fokusa klawiatury
- Semantyczny HTML: poprawna hierarchia nagłówków, listy
- Kontrast kolorów: zgodny z WCAG AA
- Obsługa języka przez system i18n

---

## 23. Przypadki użycia biznesowego

### 23.1 Zarządzanie klientami (CRM)

**Siatka jako lista klientów:**  
Tabela z kolumnami: nazwa firmy, NIP, miasto, województwo, status, opiekun handlowy. Filtrowanie po województwie i statusie "aktywny" daje natychmiastową listę roboczą. Masowa zmiana opiekuna po odejściu pracownika: zaznacz wszystkich jego klientów → masowa edycja → nowy opiekun w kilku kliknięciach.

**Wykres kołowy - podział klientów wg województwa:**  
Widget Pie Chart: wymiar = województwo, wartość = COUNT(klientów). Raport pokazuje z których regionów pochodzi baza klientów. Drill-down: kliknięcie segmentu filtruje siatkę do klientów z danego województwa.

**Spotkania z klientami w kalendarzu:**  
Tabela "Spotkania" z kolumną daty, tytułem, opiekunem. Kalendarz pokazuje kto ma spotkanie kiedy. Przeciągnięcie zdarzenia = zmiana terminu bez otwierania formularza. Kliknięcie = szczegóły i notatki.

**Formularz tworzenia spotkania z prefillem:**  
Z kartoteki klienta kliknięcie "Nowe spotkanie" otwiera `create.php?table=spotkania&fk_klient_id=123` z wypełnionym i zablokowanym polem klienta.

---

### 23.2 Magazyn i logistyka (WMS)

**Siatka produktów:**  
Kolumny: SKU, nazwa, kategoria, stan magazynowy, lokalizacja, próg minimalny. Filtr "stan < próg minimalny" + eksport CSV = gotowe zamówienie uzupełnienia towaru dla dostawcy.

**Wykres słupkowy - stan magazynu wg kategorii:**  
Widget Bar Chart: oś X = kategorie produktów, oś Y = łączny stan (SUM). Wykrywa nadmierny lub zbyt niski zapas per kategoria.

**Przyjęcie towaru przez workflow:**  
Krok 1: dostawca (FK select). Krok 2: pozycje dostawy (multi-record: produkt + ilość + numer partii). Krok 3: lokalizacja magazynowa. Prowadzi magazyniera przez cały proces bez pominięcia kroku.

---

### 23.3 Sprzedaż i raporty

**Dashboard sprzedażowy:**  
Karta KPI "Przychód miesięczny" z wartością i strzałką trendu. Karta "Liczba nowych klientów". Karta "Otwarte zlecenia". Wykres słupkowy sprzedaży per miesiąc. Jeden ekran = pełny obraz dla zarządu.

**Raport aktywności handlowców:**  
Widget Lista: kolumny = handlowiec / liczba spotkań / wartość zamówień w miesiącu. Drill-down na handlowcu filtruje jego rekordy w siatce.

**Rejestr umów:**  
Tabela umów z datą podpisania, wartością, statusem, kontrahentem. Sortowanie po dacie wygaśnięcia + filtr status = "aktywna" = lista umów do odnowienia.

---

### 23.4 Dokumenty i pliki

**Faktury sprzedażowe przy zamówieniach:**  
Do każdego rekordu zamówienia dołączana faktura PDF w zakładce Pliki. Tagi: "faktura", "2024", "VAT". Wyszukiwanie po tagu "faktura" wyświetla wszystkie faktury. Kliknięcie = pobieranie.

**Umowy z kontrahentami:**  
Każdy rekord kontrahenta ma zakładkę Pliki z podpisaną umową, aneksami, NDA. Powiązane z rekordem, bez szukania w folderach sieciowych.

**Dokumentacja techniczna produktów:**  
Do każdego produktu karty katalogowe, instrukcje obsługi, certyfikaty. Serwisant klikając produkt od razu widzi dokumentację.

**Zdjęcia stanu urządzeń (serwis):**  
Technik przesyła zdjęcia usterki przed i po naprawie jako pliki w rekordzie zlecenia. Dokumentacja fotograficzna przechowywana przy zleceniu.

---

### 23.5 Baza wiedzy i support (RAG)

**Wewnętrzna baza wiedzy firmy:**  
Załadowane: regulaminy, procedury, instrukcje stanowiskowe, dokumenty ISO. Pracownik pyta: "Jaki jest czas reakcji na zgłoszenie klienta premium?" System odpowiada cytując konkretny akapit z procedury z podaniem nazwy pliku źródłowego.

**Support produktowy:**  
Załadowane: karty techniczne, FAQ, changelog. Agent supportu pyta o parametry techniczne. Natychmiastowa odpowiedź ze źródłem. Skrócenie czasu obsługi klienta.

**Onboarding nowych pracowników:**  
Załadowane: handbook, polityki HR, benefity, schemat organizacyjny. Nowy pracownik zadaje pytania systemowi zamiast angażować HR. Filtrowanie tagami ogranicza zakres do wybranej kategorii dokumentów.

**Compliance i prawo:**  
Załadowane: RODO, regulaminy branżowe, umowy ramowe. Tag "RODO" zawęża zapytania tylko do dokumentów RODO.

---

### 23.6 Helpdesk i serwis

**Rejestracja zgłoszenia z prefillem:**  
Z kartoteki klienta kliknięcie "Nowe zgłoszenie" otwiera formularz z automatycznie wypełnionym polem klienta (`?fk_customer_id=123`). Handlowiec nie wybiera klienta ręcznie.

**Harmonogram serwisowy w kalendarzu:**  
Tabela zleceń serwisowych z datą wizyty, technikiem, adresem. Każdy technik widzi swój harmonogram. Zmiana terminu przez przeciągnięcie zdarzenia.

**Edycja zlecenia z podtabelą części:**  
W edit.php zlecenia zakładka "Części" pokazuje użyte komponenty. Dodanie nowej części z tej zakładki automatycznie powiązuje z zleceniem.

---

### 23.7 HR i zarządzanie zespołem

**Urlopy w kalendarzu:**  
Tabela wniosków urlopowych z datą, pracownikiem, statusem. Kolorowanie: żółty = oczekuje, zielony = zatwierdzone, czerwony = odrzucone. Kierownik widzi nieobecności na miesiąc.

**Onboarding przez workflow:**  
Krok 1: dane osobowe. Krok 2: sprzęt (multi-record: laptop, telefon, karta). Krok 3: dostępy systemowe (checkboxy). Krok 4: szkolenia do wykonania. HR ma kompletną checklistę, nic nie umknie.

---

### 23.8 Masowe operacje na danych

**Zmiana statusu po kampanii:**  
Filtruj siatke po kampanii → zaznacz wszystkich → masowa edycja: status = "kampania zakończona". Operacja na 500 rekordach w 3 kliknięciach.

**Migracja klientów po fuzji:**  
Zaznacz klientów przypisanych do spółki X → masowa zmiana właściciela. Audit log zachowuje historię zmiany.

**Eksport do mailingu:**  
Filtruj (status=aktywny + województwo=mazowieckie) → zaznacz wszystkich → eksportuj CSV. Gotowa lista do kampanii email.

---

### 23.9 Standaryzacja danych (Find & Replace)

**Normalizacja nazw województw:**  
Baza zawiera "woj. mazowieckie", "Mazowieckie", "mazowieckie". Find & Replace: kolumna "województwo", opcja case-insensitive + ignoruj akcenty. Standaryzacja przed raportem.

**Czyszczenie prefiksów w kodach:**  
SKU z zewnętrznego systemu z prefiksem "OLD-". Regex find `^OLD-`, zamień na pusty string. Podgląd potwierdza trafność przed zastosowaniem.

**Korekta formatu telefonów:**  
Telefony wpisane z myślnikami "123-456-789". Zamień "-" na "" w kolumnie "telefon". 20 rekordów podglądu do weryfikacji, potem zastosuj do wszystkich.

---

### 23.10 Import i migracja danych

**Migracja z Excela:**  
Eksport z Excela do CSV. Kreator importu: wybierz tabelę, zamapuj kolumny (np. "Nazwa firmy" → `company_name`). Upsert na NIP = duplikaty zaktualizowane. Import 5000 rekordów w minutę.

**Cykliczny import zamówień:**  
Zewnętrzny system eksportuje zamówienia CSV raz dziennie. Import z upsert na `numer_zamowienia`. Nowe zamówienia dodane, istniejące zaktualizowane (np. zmiana statusu).

**Uzupełnienie brakujących danych:**  
Audyt wykazał brak kodów pocztowych. Przygotuj CSV z kolumnami ID i `kod_pocztowy`. Import z upsert na ID zaktualizuje tylko to pole.

---

### 23.11 Automatyzacje procesów

**Automatyczne przypisanie opiekuna:**  
Trigger: CREATE w "Klienci", warunek: województwo = "mazowieckie", akcja Update: ustaw `opiekun_id` = ID handlowca regionalnego. Nowy klient trafia do właściwego handlowca automatycznie.

**Zamknięcie zlecenia:**  
Trigger: UPDATE "Zlecenia", warunek: `realizacja` = "100%", akcja Update: ustaw `status` = "zakończone". Zamknięcie automatyczne gdy technik wpisze 100%.

**Oznaczenie autora zmiany:**  
Trigger: UPDATE "Dokumenty", brak warunków, akcja Update: ustaw `ostatnia_zmiana_przez` = `{{ current_user.id }}`. Zawsze wiadomo kto ostatnio modyfikował dokument.

**Powiadomienie o nowym leadzie (Send notification):**  
Trigger: CREATE w "Leady", brak warunków, akcja Notify: User ID = ID managera sprzedaży, Title = "Nowy lead: {{ record.nazwa }}", Link = `/edit.php?table=leady&id={{ record.id }}`. Manager dostaje powiadomienie w systemie przy każdym nowym leadzie.

**Automatyczne zadanie po zamówieniu (Create record):**  
Trigger: CREATE w "Zamówienia", warunek: `wartosc` > 10000 (contains "1"), akcja Create record w tabeli "Zadania": `tytul` = "Weryfikacja VIP: {{ record.numer }}", `przypisane_do` = `{{ current_user.id }}`. Duże zamówienia automatycznie generują zadanie kontrolne.

**Eskalacja (OR-warunki):**  
Trigger: UPDATE "Zgłoszenia", warunek grupa OR: (`priorytet` = "krytyczny") LUB (`czas_oczekiwania` contains "48"), akcja Notify: Title = "Eskalacja: {{ record.temat }}". Powiadomienie idzie gdy spełniony JEDEN z warunków — priorytet krytyczny LUB długi czas oczekiwania.

---

### 23.12 Architektura i dokumentacja systemu

**ERD jako mapa danych:**  
Diagram ER w panelu admina pokazuje wszystkie tabele i relacje FK jednym rzutem oka. Nowy developer lub analityk biznesowy od razu rozumie strukturę danych bez czytania SQL. Kliknięcie tabeli w diagramie otwiera jej edytor schematu.

---

### 23.13 Integracja z istniejącą bazą MySQL (MySQL Gateway)

**Stopniowa migracja z systemu legacy:**  
Firma ma starą aplikację na MySQL i chce korzystać z interfejsu OpenSparrow bez przepisywania danych. Admin w zakładce External Databases rejestruje nazwy tabel MySQL (np. `klienci`, `zamowienia`). Warstwa `DatabaseFactory` automatycznie kieruje zapytania do MySQL dla tych tabel — handlowcy pracują w OpenSparrow, a dane pozostają w bazie MySQL, bez okna przestoju.

**Architektura warstwowa bez zmian we frontendzie:**  
`DatabaseFactory::make($table, $pgConn, $mysqlPdo)` zwraca `MysqlGateway` dla tabel z listy i `PostgresGateway` dla pozostałych. Obie implementują `DatabaseGatewayInterface` — logika frontendu pozostaje niezmieniona.

---

### 23.14 Anonimizacja danych osobowych (RODO)

**Wygasłe leady:**  
Leady starsze niż 2 lata nie wymagają już pełnych danych. Słownik zawiera "email, phone, imię, nazwisko". Scan Suggestions wskazuje kolumny `email`, `phone`, `first_name`, `last_name` w tabeli `leads`. Reguły ustawione z wartością `USUNIĘTO`. Cron działa miesięcznie — co 30 dni wszystkie pasujące rekordy są zanonimizowane, dane nadal istnieją w bazie (audit log), ale nie są już danymi osobowymi.

**Dane klientów po zamknięciu umowy:**  
NIP i PESEL klientów niepotrzebne po archiwizacji umów. Słownik: "pesel, nip". Suggestions wskazuje `pesel` i `nip` w tabeli `customers`. Replacement: `***`. Cron cotygodniowy; historia wykonań widoczna w zakładce Run History.

---

### 23.15 Zarządzanie pracą na tablicy (Board / Kanban)

**Pipeline sprzedaży:**  
Tabela "Szanse sprzedaży" z kolumną enum `etap` (Lead, Kwalifikacja, Oferta, Negocjacje, Wygrana, Przegrana). Handlowiec otwiera Tablicę i widzi wszystkie szanse jako karty w kolumnach odpowiadających etapom. Po telefonie z klientem przeciąga kartę z "Oferta" do "Negocjacje" — etap zmienia się natychmiast w bazie, bez otwierania formularza.

**Zarządzanie zadaniami zespołu:**  
Tabela "Zadania" z kolumną statusu (Do zrobienia, W trakcie, Do weryfikacji, Gotowe). Zespół używa Tablicy jako prostego narzędzia Kanban — przeciąganie kart między kolumnami odzwierciedla postęp prac, a liczniki w nagłówkach kolumn pokazują obciążenie na każdym etapie.

---

## 24. Tablica (Board / Kanban)

Adres: `board.php`  
Widok wizualizujący rekordy jednej tabeli jako przesuwalne karty rozłożone w kolumnach (torach), gdzie każdy tor odpowiada jednej wartości wybranej kolumny statusu (np. "Do zrobienia", "W trakcie", "Gotowe"). Przeciągnięcie karty do innego toru natychmiast zmienia status rekordu — to rdzeń zarządzania pracą znany z narzędzi typu Jira/Trello.

### Układ tablicy

- Nagłówek z nazwą tablicy oraz podtytułem: nazwa tabeli źródłowej i kolumna grupująca
- Tory (kolumny) ułożone poziomo, przewijane w poziomie gdy nie mieszczą się na ekranie
- Nagłówek toru: kropka w kolorze toru, nazwa wartości statusu, licznik kart
- Karty w torze ułożone pionowo, sortowane od najnowszego rekordu

### Karty

- Tytuł karty z konfigurowanej kolumny (`title_column`); kolumny FK pokazywane jako etykieta, nie ID
- Opcjonalne pola szczegółowe (`card_columns`) wyświetlane jako lista etykieta–wartość
- Lewa krawędź karty w kolorze toru (statusu)
- Identyfikator rekordu (`#id`) w prawym górnym rogu

### Interakcje

| Akcja | Efekt |
|-------|-------|
| Kliknięcie karty | Otwiera `edit.php` dla tego rekordu |
| Przeciągnięcie karty do innego toru | Zmienia wartość kolumny statusu przez API |

### Przeciąganie kart (Drag & Drop)

- Dostępne tylko dla roli **editor** (viewer widzi tablicę w trybie tylko do odczytu)
- Podczas przeciągania docelowy tor wyróżniany ramką
- Optymistyczny UI: karta przenosi się natychmiast; po nieudanym zapisie wraca na poprzednie miejsce
- Backend waliduje docelową wartość względem dozwolonej listy (opcje enum lub istniejące wartości) i respektuje własność rekordu (`owner_restricted`); zmiana jest zapisywana w logu audytu (`BOARD_MOVE`)
- Rekordy ze statusem spoza zdefiniowanych torów trafiają do toru "Bez kategorii"

### Konfiguracja przez admina

Panel admina → zakładka **Board**:
- Wybór tabeli źródłowej
- **Wybór kolumny statusu** (najważniejsze ustawienie) — zalecana kolumna typu enum; jej opcje i kolory (`enum_colors`) stają się torami tablicy. Dla kolumn nie-enum tory są wyprowadzane z istniejących wartości
- Podgląd torów z kolorami od razu po wyborze kolumny statusu
- Kolumna tytułu karty oraz pola szczegółowe pokazywane na kartach
- Domyślny kolor toru/karty
- Ustawienia menu: nazwa, ikona, ukrycie w menu (tablica pojawia się w menu dopiero po ustawieniu tabeli i kolumny statusu)

---

*Dokumentacja wygenerowana: 2026-07-01 | Pokrycie: wszystkie moduły FE OpenSparrow v2.9+*
