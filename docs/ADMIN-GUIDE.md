# Daftar chalane ka tareeqa

Roman Urdu operating guide. Do hisse: pehle woh cheezein jo owner ko **aik dafa** set karni hain,
phir woh jo front desk **har roz** karta hai.

Har baat code se naapi gayi hai, yaad-dasht se nahi. Jahan kuch toota hua hai wahan saaf likha hai —
aik system jo apni kharabi chhupa le, woh us kharabi se zyada nuqsan deta hai.

> Is guide ka HTML version share karne ke liye aik Artifact ke tor par bhi publish kiya gaya hai.
> Yeh file repo ki asl copy hai; kisi cheez mein farq ho to **yeh** sahi hai.


## Fehrist

**Hissa aik — Owner: aik dafa ka setup**

- [1.1 Pehla din, saat kaam](#11-pehla-din-saat-kaam)
- [1.2 Email: sab se pehle yehi](#12-email-sab-se-pehle-yehi)
- [1.3 Paisa aur commission](#13-paisa-aur-commission)
- [1.4 Kaun kya kar sakta hai](#14-kaun-kya-kar-sakta-hai)

**Hissa do — Front desk: rozana ka kaam**

- [2.1 Naya student register karna](#21-naya-student-register-karna)
- [2.2 Fees lena](#22-fees-lena)
- [2.3 Refund aur void](#23-refund-aur-void)
- [2.4 Course aur batch](#24-course-aur-batch)
- [2.5 Website](#25-website)

**Hissa teen — Har module, har screen**

- [3.0 Menu kaise banta hai](#30-menu-kaise-banta-hai)
- [3.1 System](#31-system) · [3.2 Software House](#32-software-house) · [3.3 HR](#33-hr) · [3.4 Finance](#34-finance)
- [3.5 Collaborator](#35-collaborator) · [3.6 Institute](#36-institute) · [3.7 Website](#37-website) · [3.8 Workspace](#38-workspace)

**[Maloom kharabiyan](#maloom-kharabiyan)** — woh cheezein jo abhi tooti hui hain

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

### 1.2 Email: sab se pehle yehi

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

## Hissa teen — Har module, har screen

### 3.0 Menu kaise banta hai

Admin panel mein **aath groups** aur un ke andar **110 screens** hain. System mein kul **117 modules**
hain, jin mein se **14 core** hain aur kabhi band nahi ho sakte. Poora menu sirf `app/Support/Sidebar.php`
se banta hai — kahin koi doosra menu nahi.

**Har link chaar gates se guzarta hai:** (1) module ON ho, (2) route register ho, (3) permission ho,
(4) koi extra shart ho to woh bhi. Jis parent ka koi child nahi bacha woh parent gayab; jis group ka
koi item nahi bacha woh poora group gayab.

> Agar koi screen nazar nahi aa rahi to teen mein se aik wajah hai: module band hai, permission nahi
> hai, ya woh screen abhi bani hi nahi.

Paanch panel:

| Panel | URL | Kaun |
|---|---|---|
| Admin | `/admin` | Staff — aap, front desk, accountant, HR |
| Student | `/student` | Apne courses, fees, hazri, nataij |
| Teacher | `/teacher` | Apna timetable, register, batch |
| Client | `/client` | Apne projects, invoices, documents |
| Collaborator | `/collaborator` | Apni commission, wallet, payout |

Collaborator panel poora aik switch se band ho sakta hai: `System → Modules → Collaborators`.

### 3.1 System

| Screen | Kahan | Kis kaam ki |
|---|---|---|
| Dashboard | `/admin` | Login ke baad pehla safha. Har user apne cards ki tarteeb badal sakta hai. |
| Users | `/admin/users` | Naya account, role, branch. **Role hi tay karta hai ke banda kaunsa panel dekhega.** |
| Roles | `/admin/roles` | Role banana aur permissions dena. |
| Permissions | `/admin/permissions` | Sirf dekhne ke liye: kaun si ability kis module ki. |
| Modules | `/admin/modules` | Feature on/off. **Band karne se data nahi jata** — permissions har kisi ke liye mana ho jati hain, Super Admin ke liye bhi. |
| Activity Log | `/admin/activity-log` | Har create/update/delete — kis ne kiya, purani aur nayi value. |
| Audit Trail | `/admin/audit-trail` | Wohi tabdeeliyan, alag haq ke saath. |
| Login History | `/admin/login-history` | Har login — IP, device, session ki lambai. |
| Settings | `/admin/settings` | Jo business wala banda developer ke baghair badal sake. |
| System Health | `/admin/system-health` | Queue, cache, disk, mail — **naapa hua, set kiya hua nahi.** |
| Integrity Checks | `/admin/integrity-checks` | System ne apne aap par jo proofs chalaye. |
| ~~Backups~~ | — | Menu mein hai, route nahi — kisi ko nazar nahi aata (**T65**). |

### 3.2 Software House

| Screen | Kahan | Kis kaam ki |
|---|---|---|
| Leads | `/admin/leads` | Har poochh-gachh — pehle rabte se jeeti hui client tak. |
| Clients | `/admin/clients` | Company/shakhs, un ke log, documents, portal login. |
| All Projects | `/admin/projects` | Kitne ka, kaun chala raha, kitna hua. |
| Task board | `/admin/tasks/board` | Kanban — column badalne se status badalta hai. |
| Project Payments | `/admin/project-payments` | **Payment kabhi edit nahi hoti** — void kar ke nayi banti hai. |
| Tasks | `/admin/tasks` | List ki shakl mein. |
| Time Tracking | `/admin/time` | Poora haq na ho to sirf apne ghante. |

### 3.3 HR

| Screen | Kahan | Kis kaam ki |
|---|---|---|
| Employees | `/admin/employees` | Payroll par jo hai — aur jo tha. |
| Departments | `/admin/departments` | Woh org chart jis se HR grouping karti hai. |
| Attendance | `/admin/attendance` | Aik din ki hazri: punch, manual mark, din band karna. |
| Leaves | `/admin/leaves` | Chhutti ki darkhwastein. |
| Leave Balances | `/admin/leave-balances` | Kis ki kitni chhutti bachi. |
| Payroll | `/admin/payroll-runs` | Draft → generated → locked → paid. **Peeche nahi ja sakta.** |
| Salary Slips | `/admin/payslips` | Accountant parh aur print kar sake bagair payroll lock karne ka haq liye. |
| Salary Structures | `/admin/salary-structures` | Tankhwah ki history. |
| Advances | `/admin/advances` | Kya diya, kitna aaya, kitna baqi. |
| My HR | `/admin/my/…` | **Sirf tab dikhta hai jab login wale ka apna Employee record ho** — warna 404. |
| HR Setup | `/admin/designations` … | Designations, Shifts, Holidays, Leave Types, Salary Components. |

**Work Shifts ahem hai:** der se aana/jaldi jana usi shift ki window se naapa jata hai.

### 3.4 Finance

| Screen | Kahan | Kis kaam ki |
|---|---|---|
| Invoices | `/admin/invoices` | **Invoice mein paisa nahi hota** — har rupaya aik receipt hai. |
| Payments | `/admin/payments` | Har rupaya jo aaya. Sirf parhne ke liye. **Do permissions chahiye.** |
| Expenses | `/admin/expenses` | **Sirf manzoor shuda kharcha report mein ginta hai.** |
| Expense Approvals | `/admin/expenses/approvals` | Manzoori ke intezar mein. |
| Expense Sheet | `/admin/expenses/sheet` | Wohi kharchay din, hafta aur mahina ke hisaab se — chart, category/status split aur sheet. **Table wale filters yahan bhi lagte hain.** |
| Income | `/admin/income` | Woh aamdani jo na fees hai na client ka paisa. |
| Payment Methods | `/admin/payment-methods` | Kaunsa form kaunsa tareeqa offer kar sakta hai. |
| Finance Categories | `/admin/finance-categories` | **Code aik bar banne ke baad fix hai.** |
| Finance Reports | `/admin/reports/finance` | Chaaron **cash basis** par — jo paisa chala, jiska waada hua woh nahi. |

### 3.5 Collaborator

| Screen | Kahan | Kis kaam ki |
|---|---|---|
| Collaborators | `/admin/collaborators` | Partners jo karobar laate hain. |
| Applications | `/admin/collaborators/pending` | Manzoori ka intezar. |
| Commissions | `/admin/commissions` | **Yahan kuch bhi edit nahi hota.** Durustagi ulti entry se. |
| Commission Skips | `/admin/commission-skips` | Jin receipts par commission nahi bani — aur kyun. |
| Discrepancies | `/admin/commission-discrepancies` | Waade jo apni qeemat se zyada release ho gaye. |
| Wallets | `/admin/wallets` | Wallet ledger ka **cache** hai — har figure dobara nikala ja sakta hai. |
| Payouts | `/admin/payouts` | Kya diya gaya, kya baqi. |
| Referral Visits | `/admin/referral-visits` | Partner ke link par har click. |
| ~~Referrals~~ | — | Menu mein hai, route nahi (**T65**). |

### 3.6 Institute

**Courses** — `All Courses` (catalogue), `Categories` (website par grouping; **registration screen par
category nahi aati**), `Materials` (kis ko diya, kab tak, kitni bar download). Course ka **Outline**
menu mein nahi — woh course ke apne safhe par hai.

**Students** — **`Register a Student`** (front desk ka sab se zyada istemaal hone wala safha, §2.1),
`All Students`, `Admissions` (**har qatar aik student aik course par**), `Course Inquiries`
(counsellor ki qatar, agle call ke hisaab se), `Applications`, `Demo Classes` (ustad aur kamra rokta
hai, is liye clash check hota hai), `ID Cards` (aik hi qadam mein number aur print).

**Teachers / Classrooms / Batches** — `Teachers`, `Classrooms` (**virtual kamra** aik saath jitni
chahein classes rakh sakta hai), `All Batches` (capacity **recount** se), `Timetable` (haftawar),
`Classes` (tareekh wali; cancel karne par calendar par rehti hai), `Attendance`, `Progress`.

**Fees** — `Student Fees` (**paisa lene ka dialog yahin**, §2.2), `Fee Receipts` (**edit nahi hoti** —
void kar ke nayi), `Fee Collection` (Due today / Next 7 days / Overdue / Advances — **is par Collect
ka button nahi**, T63), `Fee Reminders`. Installments aur Discounts ki alag list nahi — woh charge ke
apne safhe par hain.

**Assessments** — `Assignments` (**aik assignment aik batch ka**), `Exams`, `Results`, `Grade Scales`
(**jis scale se grade mil chuki woh mehfooz ho jata hai**), `Certificates` (draft ka number nahi; jari
shuda pakka aur verifiable), `Print Templates`.

### 3.7 Website

24 screens. Upar ke nau site ki apni tarteeb hain: `Website Overview`, `Sections` (**tabdeeli draft
rehti hai jab tak publish na ho**), `Menus`, `Pages`, `CTA Blocks`, `FAQs`, `FAQ Categories`,
`Media Library` (**file apne content se check hoti hai, naam se nahi**), `SEO`.

Baqi content: `Services` + categories + `Technologies`, `Portfolio` + categories, `Team` (**yeh
website ka content hai, account nahi — koi login nahi banta**), `Testimonials`, `Student Reviews`,
`Success Stories`, `Blog Posts` + categories + tags, `Events`, `Jobs` + `Job Applications` (**CV
kabhi private disk se bahar nahi jate**), `Contact Inquiries`.

### 3.8 Workspace

| Screen | Kahan | Kis kaam ki |
|---|---|---|
| Support Tickets | `/admin/tickets` | **Ticket kabhi delete nahi hota** — band hota hai, mitta nahi. |
| Support Desks | `/admin/ticket-departments` | Kahan file hote hain, kaun uthata hai, kitne waqt ka waada. |
| Meetings | `/admin/meetings` | Jo meeting nahi hogi woh **wajah ke saath** cancel hoti hai. |
| Messages | `/admin/messages` | Kuch delete nahi hota — ghalat paighaam doosre se theek hota hai. |
| Notifications | `/admin/notifications` | Archive karna delete nahi hai. |
| Reports | `/admin/reports` | Har figure usi service se — report aur screen ikhtilaf nahi kar sakte. |
| Advanced Reports | `/admin/advanced-reports` | **Har qatar aik student aik course par** — period (joining date), course, batch, student status, payment status aur search se filter. Excel / CSV / PDF / Print mein **wohi qataarein** jo screen par hain. Fees ki raqam sirf `view_financial` walon ko; baqi ko column hi nahi milta. Student ka naam kholein to course, batch, progress — aur `view_financial` ke saath paisay ki poori history. |
| Analytics | `/admin/analytics` | Har chart wohi service parhta hai jo us module ki screen parhti hai. |
| My Exports | `/admin/report-exports` | Muqarrara waqt tak, phir khud khatam. |
| Global Search | upar search box | Jo kuch aap dekh sakte hain, aik jagah. |
| ~~Files~~ | — | Menu mein hai, route nahi (**T65**). |


## Maloom kharabiyan

| Kya | Kya hota hai | Abhi kya karein |
|---|---|---|
| **T64** Student apni fee slip nahi chhap sakta | Student portal mein slip ka safha har student ke liye 500 deta hai | Front desk admin panel se chhap kar de |
| **T63** Fee Collection screen se paisa nahi liya ja sakta | Jis screen par cashier kaam karta hai us par "Collect" ka button nahi | Student ki fee screen khol kar wahan se lein |
| **T56** Raat ka hisaab-check nakaam | `integrity:verify --suite=all` mein `all` suite mojood hi nahi — roz 02:15 par bina kuch kiye error. Paise ke khaton ki rozana tasdeeq **kabhi nahi** hoti | Scheduler se `--suite=all` hatayein |
| **T55** Commission nahi banti | Worker `financial` qatar nahi sunta | Worker ki command mein `financial` shamil karein |
| **T65** Teen menu links kabhi nazar nahi aate | Backups, Files, Collaborator → Referrals — menu mein likhe hain magar route register nahi hua | Backup abhi scheduler se chalta hai, screen se nahi |
