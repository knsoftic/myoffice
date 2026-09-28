# Daftar chalane ka tareeqa

Roman Urdu operating guide. Do hisse: pehle woh cheezein jo owner ko **aik dafa** set karni hain,
phir woh jo front desk **har roz** karta hai.

Har baat code se naapi gayi hai, yaad-dasht se nahi. Jahan kuch toota hua hai wahan saaf likha hai —
aik system jo apni kharabi chhupa le, woh us kharabi se zyada nuqsan deta hai.

> Is guide ka HTML version share karne ke liye aik Artifact ke tor par bhi publish kiya gaya hai.
> Yeh file repo ki asl copy hai; kisi cheez mein farq ho to **yeh** sahi hai.

---

## Hissa aik — Owner: aik dafa ka setup

### 1.1 Pehla din, saat kaam

| # | Kaam | Na kiya to |
|---|---|---|
| 1 | Apna password badlein | Pehli login par system khud `/account/password` par le jata hai aur us se pehle koi screen nahi kholta |
| 2 | `Settings → Company`: naam, logo, pata, phone | Har receipt, invoice aur certificate par `MyOffice ERP` chhapega, logo ki jagah do harf, pata khali |
| 3 | `Settings → Institute`: numbering prefixes | Defaults chalte rahenge (`STD`, `REG-`, `ADM-`, `FS-`, `CERT`). Jari shuda number **kabhi** dobara number nahi hota — baad mein prefix badalne se do alag silsile ban jate hain |
| 4 | `Settings → Email`: SMTP | §1.2 — sab se ahem |
| 5 | `Settings → Finance`: bank ki tafseel | Client ki invoice par account number hi nahi hoga |
| 6 | `Settings → Collaborators`: commission ke usool | §1.3 |
| 7 | Server par `APP_URL` | Certificate ka QR isi par jata hai (aur jari shuda certificate ka URL badla nahi ja sakta). Aur agar request ka host `APP_URL` se na mile to **har** request routing se pehle 400 de kar rad hoti hai — poori site band lagti hai |

### 1.2 Email — sab se pehle yehi

**Abhi ki haalat:** transport `log` par hai. System **aik bhi email nahi bhejta**. Har notification,
har password, har reminder `storage/logs/laravel.log` mein **plain text** mein likha jata hai. Screen
par "sent" likha aata hai; inbox mein kuch nahi pohanchta.

Gmail ke liye `Settings → Email`:

| Khana | Kya likhein |
|---|---|
| Transport | SMTP server |
| SMTP host | `smtp.gmail.com` |
| Port | `587` |
| Encryption | TLS |
| Username | poora Gmail address |
| Password | **App Password** — account ka apna password kaam nahi karega |
| From address | wohi Gmail address |

**Do jaal:**

1. Transport SMTP chun kar host khali chhor dein to system chupke se purani environment-file wali
   setting par reh jata hai. Koi error nahi — bas mail nahi jati.
2. SMTP bhar dena kaafi **nahi**. `Settings → Support, Meetings & Messaging` mein
   *"Send notifications by email"* aik doosra master switch hai jo **band** hai. Us ke baghair fee
   reminder, ticket ya meeting ki koi email copy nahi banegi.

Usi screen par **Send test email** ka button hai. Woh chalane tak yaqeen na karein.

> **Email theek karne se pehle koi student login na banayein.** Student ka password sirf email mein
> hota hai — database mein kahin nahi, queue mein bhi nahi. Mail kaam na kare to woh password hamesha
> ke liye khatam; naya account banana parega. Istisna: nayi **Register a Student** screen par aap
> khud password type karte hain, wahan email par inhisar nahi.

### 1.3 Paisa aur commission

> **Sab se khatarnak — server ki setting.** Commission ka kaam `financial` qatar par jata hai, magar
> tamam dastawezaat mein likhi command `--queue=high,default` hai — jis mein `financial` hai hi nahi.
> Fee payment record hoti hai, commission ka kaam qatar mein jata hai, aur **kabhi nahi chalta**. Sab
> theek dikhta hai. Sahi command: `--queue=high,financial,default,exports` (**T55**)

Do faisle pehle din:

- **Admission fee aur registration fee par commission — dono band hain.** Partner ko sirf course fee
  par milegi. Commission ki entry baad mein badli nahi ja sakti, sirf ulti entry dali ja sakti hai.
- **"Hold commission for" abhi 0 din hai.** Wasooli ke foran baad paisa nikalne ke qabil. Agle din
  fee refund ho gayi to paisa ja chuka hoga. **15–30 din** rakhna asal bachao hai.

**Tax ki do alag settings hain**, aur yeh jaan-boojh kar alag hain:

| Kahan | Kis cheez par |
|---|---|
| `Settings → Institute → Tax on fees` | School ki **fees** |
| `Settings → Finance → Charge tax on invoices` | Software house ki **client invoices** |

Aik set karne se doosri jagah kuch nahi hota.

Usi Institute screen par do nayi cheezein, dono **optional**: **Extra fee** (har registration par aik
bar — kit, lab charge, ID card) aur **Tax on fees** (feesad, discount ke *baad*). Dono sifar chhor
dein to bill par woh line **nazar hi nahi aati** — sifar wali line nahi, bilkul line nahi.

### 1.4 Kaun kya kar sakta hai

Menu ka har link chaar shartein poori karne par dikhta hai: module ON, route mojood, permission
hasil, aur koi extra shart ho to woh bhi. Jis parent ka koi child nahi bacha woh parent gayab.

**Menu chhupana security nahi hai** — jis ka item chhupa hai woh URL type kar ke bhi andar nahi ja
sakta; route par wohi permission dobara check hoti hai.

Teen cheezein jo **by default kisi ke paas nahi**:

- **Private messages parhna** (`messages.view_any`) — kisi ke paas nahi, Admin ke bhi nahi.
- **Partner ka payout maangna** — seeded Collaborator role mein yeh permission nahi. Setting ON karna
  kaafi nahi; role editor se permission bhi deni hogi.
- **Website Manager apne hi public safhe ON nahi kar sakta** — woh checkbox sirf `settings.edit` wale
  ke paas hai, jo SMTP aur security bhi khol deta hai (**T58**).

**Module band karne se data nahi jata** — sirf menu aur screens band hote hain, aur us ki permissions
har kisi ke liye mana ho jati hain, Super Admin ke liye bhi. 14 module "core" hain aur kabhi band nahi
ho sakte.

---

## Hissa do — Front desk: rozana ka kaam

### 2.1 Naya student register karna

`Students → Register a Student`. Do qadam. **Pehla qadam mehfooz ho jata hai**, is liye beech mein
browser band ho jaye to student aur uska login bana rehta hai.

**Qadam 1 — student ki maloomat**

1. **Naam aur phone** lazmi. Baqi shanakht ikhtiyari.
2. **Email** lazmi — yehi username hai. Kisi aur ke paas pehle se ho to system saaf mana kar dega.
3. **Password aur dobara password** — aap tay karte hain aur student ko batate hain. **Koi email nahi
   jati**: jo password aap ko maloom hai usay inbox aur mail-log mein daalna do copies banata hai,
   faida koi nahi. Student pehli login par apna password khud chunega — system majboor karta hai.
4. Additional information (guardian, taleem, joining date) — sab ikhtiyari.
5. **Next step — courses & billing**.

**Qadam 2 — courses aur bill**

1. **Courses tick karein.** Saare published courses seedha, **koi category nahi**. Har aik ke neeche
   uski apni tafseel. Search box bhi hai.
2. **Bill khud banta jata hai** — dayin taraf.
3. **Discount** — wajah likhna **lazmi** hai.
4. **"Charge the admission and registration fee once"** — by default ticked. Student aik bar register
   hota hai, chahe kitne bhi course le.
5. **Abhi kitna de raha hai** — khali chhor dein agar baad mein dena hai.
6. **Complete registration**.

Bill ka hisaab:

```
course fees + admission fee + registration fee + extra fee   = subtotal
subtotal − discount − scholarship                             = taxable
taxable + tax                                                 = total payable
total payable − abhi di gayi raqam                            = balance
```

**Jo khud hota hai:** har ticked course ka **apna admission** banta hai — apne number, batch, register
aur certificate ke saath. Registration number **aik hi** banta hai, poore student ke liye. Discount
aur tax courses par unke hisse ke mutabiq baant diye jate hain, aur jo raqam abhi di gayi woh bhi — is
liye har course ka balance sahi rehta hai.

### 2.2 Fees lena

Student ki fee screen kholein → **Collect payment**.

1. **Amount** — jo bachta hai woh pehle se bhara hua. Badal sakte hain.
2. **Received on** — aaj ki tareekh pehle se. Aage ki tareekh nahi chalti. Purani chal jati hai magar
   30 din se zyada purani ke liye ijazat chahiye — aur commission usi din ke usool se banti hai jo
   tareekh aap likhte hain.
3. **How it was paid** — bank/cheque/wallet chunte hi reference ka khana khud khul jata hai.
4. **Against which installment** — agar plan bana hua hai.
5. **Record the receipt**.

- **Do bar daba diya?** Paisa do bar nahi jayega. Form kholte waqt aik guard number banta hai; wohi
  form dobara bheja jaye to system pehli receipt wapas karta hai.
- **Bill se zyada paisa?** Jaiz hai — **advance** ban jata hai aur Fee Collection par uska apna tab
  hai. Is liye amount par koi upper limit nahi.
- **Student ko koi ittila nahi jati.** Parchi register se chhap kar dein.

### 2.3 Refund aur void

Paise ka record kabhi mitaya ya badla nahi jata. Durustagi hamesha aik **ulti entry** se hoti hai jo
asal ka hawala deti hai.

- **Refund** — paisa wapas gaya. Us receipt ki kamai hui commission usi nisbat se ulti ho jati hai.
- **Void** — receipt ghalti se bani thi (cheque bounce, ghalat student). Wajah lazmi.

### 2.4 Course aur batch

- Naya course **draft** hota hai; publish tab hota hai jab zaroori khane bhare hon — screen batati hai
  kya kami hai.
- **Sirf published courses** registration screen par aate hain.
- **Batch aik hi course ka hota hai.** Admission screen par batch ka khana abhi **number** maangta hai
  — Batches screen se ID dekh kar likhni parti hai. Picker banana baqi hai.

### 2.5 Website

Public site poori tarah CMS se chalti hai. Do cheezein waqt zaya karwati hain:

1. **Menu badalne par woh khud website par nahi aata.** Header aur footer ke publish shuda snapshot
   mein menu pehle se pakka hota hai — dobara publish karna parta hai.
2. **Code deploy karne se public page ka cache saaf nahi hota** (**T59**). knsoftic.com par waqai hua:
   `/trainers` band karne ke baad bhi khulta raha. Deploy ke baad `php artisan cache:clear`.

---

## Maloom kharabiyan

| Kya | Kya hota hai | Abhi kya karein |
|---|---|---|
| **T64** Student apni fee slip nahi chhap sakta | Student portal mein slip ka safha har student ke liye 500 deta hai | Front desk admin panel se chhap kar de |
| **T63** Fee Collection screen se paisa nahi liya ja sakta | Jis screen par cashier kaam karta hai us par "Collect" ka button nahi | Student ki fee screen khol kar wahan se lein |
| **T56** Raat ka hisaab-check nakaam | `integrity:verify --suite=all` mein `all` suite mojood hi nahi — roz 02:15 par bina kuch kiye error. Paise ke khaton ki rozana tasdeeq **kabhi nahi** hoti | Scheduler se `--suite=all` hatayein |
| **T55** Commission nahi banti | Worker `financial` qatar nahi sunta | Worker ki command mein `financial` shamil karein |
| **T65** Teen menu links kabhi nazar nahi aate | Backups, Files, Collaborator → Referrals — menu mein likhe hain magar route register nahi hua | Backup abhi scheduler se chalta hai, screen se nahi |
