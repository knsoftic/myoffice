@php
    use Illuminate\Support\Facades\Route;

    $companyName = trim((string) site_setting('company.name', 'MyOffice ERP'));
    $tagline = trim((string) site_setting('company.tagline', 'One system for your software house and training institute.'));
    $contactEmail = trim((string) site_setting('contact.email', 'info@myoffice.test'));
    $contactPhone = trim((string) site_setting('contact.phone', '+92 42 0000000'));
    $contactAddress = trim((string) site_setting('contact.address', 'Main Boulevard, Gulberg III, Lahore, Pakistan'));
    $whatsapp = trim((string) site_setting('contact.whatsapp', '923000000000'));
    $hoursRaw = site_setting('contact.business_hours');
    $businessHours = 'Mon – Sat: 9:00 AM – 6:00 PM (PKT)';
    if (is_string($hoursRaw) && trim($hoursRaw) !== '') {
        $businessHours = $hoursRaw;
    }

    $loginUrl = Route::has('login') ? route('login') : url('/login');
    $isPreview = (bool) data_get($site ?? null, 'isPreview', false);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#090D16">
    <meta name="description" content="MyOffice ERP - The all-in-one unified operating system built for software houses, dev agencies, and modern IT training institutes. Manage student admissions, fee vouchers, sprint tracking, and client billing under one roof.">

    <title>{{ $companyName }} — Next-Gen Unified ERP for Software Houses &amp; IT Academies</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700,800,900&display=swap">

    @include('site.partials.theme-script')
    @include('site.partials.fx-script')

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @include('layouts.partials.brand-theme')

    <style>
        /* Custom Aurora & Mesh Backgrounds */
        .bg-mesh-dark {
            background-color: #090D16;
            background-image: 
                radial-gradient(at 15% 15%, rgba(99, 102, 241, 0.12) 0px, transparent 55%),
                radial-gradient(at 85% 10%, rgba(139, 92, 246, 0.12) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(6, 182, 212, 0.06) 0px, transparent 65%),
                radial-gradient(at 80% 85%, rgba(99, 102, 241, 0.10) 0px, transparent 55%),
                radial-gradient(at 10% 90%, rgba(139, 92, 246, 0.08) 0px, transparent 50%);
        }
        
        .glow-indigo {
            box-shadow: 0 0 50px -10px rgba(99, 102, 241, 0.35);
        }

        .glow-cyan {
            box-shadow: 0 0 50px -10px rgba(6, 182, 212, 0.35);
        }

        .glow-card {
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.36);
        }

        /* Glassmorphism Borders */
        .glass-border {
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .glass-border-hover:hover {
            border-color: rgba(99, 102, 241, 0.4);
            box-shadow: 0 0 25px -5px rgba(99, 102, 241, 0.25);
        }

        /* Subtle grid background */
        .bg-tech-grid {
            background-size: 40px 40px;
            background-image: 
                linear-gradient(to right, rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
        }

        /* Text gradient presets */
        .text-gradient-primary {
            background: linear-gradient(135deg, #FFFFFF 0%, #E2E8F0 50%, #818CF8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .text-gradient-accent {
            background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 50%, #06B6D4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .text-gradient-cyan {
            background: linear-gradient(135deg, #38BDF8 0%, #818CF8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>
<body 
    x-data="{
        scrolled: false,
        mobileMenuOpen: false,
        activePersona: 'software',
        billingCycle: 'monthly',
        demoModalOpen: false,
        activeFaq: 0,
        emailInput: '',
        emailSubmitted: false,
        init() {
            window.addEventListener('scroll', () => {
                this.scrolled = window.scrollY > 30;
            });
        },
        submitNewsletter() {
            if (this.emailInput.includes('@') && this.emailInput.includes('.')) {
                this.emailSubmitted = true;
                setTimeout(() => { this.emailSubmitted = false; this.emailInput = ''; }, 4000);
            }
        }
    }"
    class="min-h-screen bg-[#090D16] text-slate-100 font-sans antialiased selection:bg-indigo-500/30 selection:text-white relative overflow-x-hidden"
>

    {{-- Background Glow Orbs --}}
    <div class="fixed inset-0 pointer-events-none -z-10 overflow-hidden" aria-hidden="true">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-[900px] h-[550px] bg-gradient-to-tr from-indigo-600/15 via-violet-600/10 to-cyan-500/10 rounded-full blur-[140px]"></div>
        <div class="absolute top-[40%] -left-48 w-[600px] h-[600px] bg-indigo-900/10 rounded-full blur-[150px]"></div>
        <div class="absolute top-[70%] -right-48 w-[600px] h-[600px] bg-violet-900/10 rounded-full blur-[150px]"></div>
    </div>

    {{-- =========================================================================
         1. MODERN NAVIGATION BAR (Sticky/Floating)
         ========================================================================= --}}
    <header 
        class="fixed top-0 inset-x-0 z-50 transition-all duration-300"
        :class="scrolled ? 'py-3 bg-[#090D16]/85 backdrop-blur-xl border-b border-white/[0.08] shadow-[0_10px_30px_rgba(0,0,0,0.5)]' : 'py-5 bg-transparent'"
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between">
                
                {{-- Brand Logo --}}
                <a href="#top" class="flex items-center gap-3 group focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded-lg">
                    <div class="relative flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 via-indigo-600 to-violet-600 p-0.5 shadow-lg shadow-indigo-500/25 group-hover:shadow-indigo-500/40 transition-all duration-300">
                        <div class="w-full h-full bg-[#0B0F19] rounded-[10px] flex items-center justify-center">
                            {{-- Tech Icon: Interlocking Dual Cubes / Unified System --}}
                            <svg class="w-5 h-5 text-indigo-400 group-hover:text-cyan-300 transition-colors duration-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                            </svg>
                        </div>
                    </div>
                    <div class="flex flex-col">
                        <div class="flex items-center gap-1.5">
                            <span class="text-lg font-bold tracking-tight text-white group-hover:text-indigo-200 transition-colors">MyOffice</span>
                            <span class="text-xs font-black tracking-wider uppercase px-1.5 py-0.5 rounded bg-gradient-to-r from-indigo-500/20 to-cyan-500/20 text-indigo-300 border border-indigo-500/30">ERP</span>
                        </div>
                        <span class="text-[10px] text-slate-400 font-medium tracking-wide">Software &amp; Academy</span>
                    </div>
                </a>

                {{-- Desktop Navigation Links --}}
                <nav class="hidden md:flex items-center gap-1 lg:gap-2 px-3 py-1.5 rounded-full bg-white/[0.03] border border-white/[0.08] backdrop-blur-md shadow-inner">
                    <a href="#features" class="px-3.5 py-1.5 text-sm font-medium text-slate-300 hover:text-white hover:bg-white/[0.06] rounded-full transition-all duration-200">
                        Features
                    </a>
                    <a href="#solutions" class="px-3.5 py-1.5 text-sm font-medium text-slate-300 hover:text-white hover:bg-white/[0.06] rounded-full transition-all duration-200">
                        Dual Solutions
                    </a>
                    <a href="#pricing" class="px-3.5 py-1.5 text-sm font-medium text-slate-300 hover:text-white hover:bg-white/[0.06] rounded-full transition-all duration-200">
                        Pricing
                    </a>
                    <a href="#faqs" class="px-3.5 py-1.5 text-sm font-medium text-slate-300 hover:text-white hover:bg-white/[0.06] rounded-full transition-all duration-200">
                        FAQs
                    </a>
                    <a href="#contact" class="px-3.5 py-1.5 text-sm font-medium text-slate-300 hover:text-white hover:bg-white/[0.06] rounded-full transition-all duration-200">
                        Contact
                    </a>
                </nav>

                {{-- Right Actions: Theme Toggle, Sign In, Book Demo CTA --}}
                <div class="hidden sm:flex items-center gap-3">
                    
                    {{-- Theme Toggle (Light/Dark/System) --}}
                    <div class="flex items-center rounded-lg bg-white/[0.04] p-1 border border-white/[0.08]" role="group" aria-label="Theme switch">
                        <button
                            type="button"
                            x-on:click="$store.theme?.set('dark')"
                            class="p-1.5 rounded-md text-indigo-400 bg-white/[0.08] shadow-sm hover:text-white transition-colors"
                            title="Dark Mode"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                            </svg>
                        </button>
                    </div>

                    {{-- Sign In --}}
                    <a 
                        href="{{ $loginUrl }}" 
                        class="text-sm font-semibold text-slate-300 hover:text-white px-3 py-2 transition-colors flex items-center gap-1.5 group"
                    >
                        <span>Sign In</span>
                        <svg class="w-3.5 h-3.5 text-slate-400 group-hover:translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>

                    {{-- Gradient CTA Button --}}
                    <a 
                        href="#pricing"
                        class="relative inline-flex items-center justify-center gap-2 px-4.5 py-2.5 text-sm font-semibold text-white rounded-xl bg-gradient-to-r from-indigo-500 via-indigo-600 to-violet-600 hover:from-indigo-400 hover:via-indigo-500 hover:to-violet-500 shadow-[0_0_20px_rgba(99,102,241,0.4)] hover:shadow-[0_0_30px_rgba(99,102,241,0.6)] transition-all duration-300 active:scale-[0.98]"
                    >
                        <span>Get Started Free</span>
                        <svg class="w-4 h-4 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </a>
                </div>

                {{-- Mobile Menu Hamburger Button --}}
                <div class="flex items-center gap-2 md:hidden">
                    <a href="{{ $loginUrl }}" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-white/[0.05] text-slate-200 border border-white/10">Sign In</a>
                    <button 
                        type="button" 
                        @click="mobileMenuOpen = !mobileMenuOpen"
                        class="p-2 rounded-xl bg-white/[0.05] border border-white/10 text-slate-300 hover:text-white focus:outline-none"
                        aria-label="Toggle Navigation Menu"
                    >
                        <svg x-show="!mobileMenuOpen" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                        </svg>
                        <svg x-show="mobileMenuOpen" x-cloak class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

            </div>
        </div>

        {{-- Mobile Dropdown Drawer --}}
        <div 
            x-show="mobileMenuOpen" 
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            class="md:hidden border-b border-white/10 bg-[#090D16]/95 backdrop-blur-2xl px-4 pt-3 pb-6 space-y-3"
        >
            <div class="flex flex-col space-y-1">
                <a @click="mobileMenuOpen = false" href="#features" class="px-3 py-2 text-base font-medium text-slate-200 hover:bg-white/[0.06] rounded-lg">Features</a>
                <a @click="mobileMenuOpen = false" href="#solutions" class="px-3 py-2 text-base font-medium text-slate-200 hover:bg-white/[0.06] rounded-lg">Dual Solutions</a>
                <a @click="mobileMenuOpen = false" href="#pricing" class="px-3 py-2 text-base font-medium text-slate-200 hover:bg-white/[0.06] rounded-lg">Pricing Plans</a>
                <a @click="mobileMenuOpen = false" href="#faqs" class="px-3 py-2 text-base font-medium text-slate-200 hover:bg-white/[0.06] rounded-lg">FAQs</a>
                <a @click="mobileMenuOpen = false" href="#contact" class="px-3 py-2 text-base font-medium text-slate-200 hover:bg-white/[0.06] rounded-lg">Contact Us</a>
            </div>
            <div class="pt-2 border-t border-white/10 flex flex-col gap-2">
                <a href="{{ $loginUrl }}" class="w-full text-center py-2.5 text-sm font-semibold text-slate-200 bg-white/[0.05] border border-white/10 rounded-xl">Sign In to Workspace</a>
                <a @click="mobileMenuOpen = false" href="#pricing" class="w-full text-center py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-indigo-500 to-violet-600 rounded-xl shadow-lg">Start Free Trial</a>
            </div>
        </div>
    </header>

    {{-- =========================================================================
         2. REVAMPED HERO SECTION
         ========================================================================= --}}
    <section id="top" class="relative pt-32 pb-20 sm:pt-40 sm:pb-28 lg:pt-44 lg:pb-36 overflow-hidden">
        
        {{-- Subtle Grid & Light Bars --}}
        <div class="absolute inset-0 bg-tech-grid opacity-35 [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_75%)] pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
            
            {{-- Pill Badge at top --}}
            <div class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-indigo-500/10 border border-indigo-500/25 backdrop-blur-md mb-8 group hover:border-indigo-500/50 transition-all duration-300">
                <span class="flex h-2 w-2 relative">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-cyan-400"></span>
                </span>
                <span class="text-xs sm:text-sm font-semibold text-indigo-300 group-hover:text-white transition-colors">
                    ✨ Next-Gen Management Platform | v2.0 Live
                </span>
                <span class="hidden sm:inline-block text-[11px] font-bold uppercase tracking-wider bg-indigo-500/20 text-indigo-200 px-2 py-0.5 rounded-full border border-indigo-400/20">
                    Dual Engine
                </span>
            </div>

            {{-- Bold Headline --}}
            <h1 class="text-4xl sm:text-6xl lg:text-7xl font-extrabold tracking-tight max-w-5xl mx-auto leading-[1.08] text-balance">
                Scale Your Software House &amp; IT Academy in 
                <span class="text-gradient-accent">One Unified System</span>
            </h1>

            {{-- Subtitle --}}
            <p class="mt-6 sm:mt-8 text-lg sm:text-xl lg:text-2xl text-slate-300 max-w-3xl mx-auto leading-relaxed text-pretty font-normal">
                Stop toggling between fragmented spreadsheets, Jira boards, and paper fee registers. MyOffice ERP synchronizes student admissions, batch timetables, developer sprints, and multi-currency client billing into a single commanding hub.
            </p>

            {{-- Dual CTAs --}}
            <div class="mt-10 sm:mt-12 flex flex-col sm:flex-row items-center justify-center gap-4 sm:gap-5">
                <a 
                    href="#pricing"
                    class="w-full sm:w-auto inline-flex items-center justify-center gap-3 px-8 py-4 text-base font-bold text-white rounded-2xl bg-gradient-to-r from-indigo-500 via-indigo-600 to-violet-600 hover:from-indigo-400 hover:via-indigo-500 hover:to-violet-500 shadow-[0_0_35px_rgba(99,102,241,0.45)] hover:shadow-[0_0_50px_rgba(99,102,241,0.7)] transition-all duration-300 transform hover:-translate-y-0.5 active:scale-[0.98]"
                >
                    <span>Start Free Trial</span>
                    <svg class="w-5 h-5 text-indigo-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </a>

                <button 
                    type="button"
                    @click="demoModalOpen = true"
                    class="w-full sm:w-auto inline-flex items-center justify-center gap-3 px-7 py-4 text-base font-semibold text-slate-200 rounded-2xl bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 hover:border-white/20 backdrop-blur-xl transition-all duration-300 group"
                >
                    <span class="w-7 h-7 rounded-full bg-indigo-500/20 flex items-center justify-center text-indigo-300 group-hover:bg-indigo-500 group-hover:text-white transition-colors">
                        <svg class="w-3.5 h-3.5 fill-current ml-0.5" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                    </span>
                    <span>Watch Interactive Demo</span>
                </button>
            </div>

            {{-- Social Proof / Live Metrics Bar --}}
            <div class="mt-14 sm:mt-16 max-w-4xl mx-auto pt-8 border-t border-white/[0.08] grid grid-cols-2 sm:grid-cols-4 gap-6 sm:gap-8 items-center justify-center">
                <div class="flex flex-col items-center">
                    <span class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">99.9%</span>
                    <span class="text-xs text-slate-400 font-medium mt-1">Uptime SLA Guaranteed</span>
                </div>
                <div class="flex flex-col items-center">
                    <span class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">250+</span>
                    <span class="text-xs text-slate-400 font-medium mt-1">Active Tech Teams</span>
                </div>
                <div class="flex flex-col items-center">
                    <div class="flex items-center gap-1 text-amber-400 mb-0.5">
                        @for ($i = 0; $i < 5; $i++)
                            <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                            </svg>
                        @endfor
                        <span class="text-white text-xs font-bold ml-1">4.9 / 5</span>
                    </div>
                    <span class="text-xs text-slate-400 font-medium">Verified Capterra &amp; G2</span>
                </div>
                <div class="flex flex-col items-center">
                    <span class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">$42M+</span>
                    <span class="text-xs text-slate-400 font-medium mt-1">Fee &amp; Invoices Processed</span>
                </div>
            </div>

            {{-- =================================================================
                 HERO MOCKUP / DASHBOARD PREVIEW WITH FLOATING KPI CHIPS
                 ================================================================= --}}
            <div class="mt-16 sm:mt-20 relative max-w-6xl mx-auto">
                
                {{-- Ambient Light behind dashboard --}}
                <div class="absolute -inset-4 bg-gradient-to-r from-indigo-500/25 via-violet-500/20 to-cyan-500/20 rounded-[2.5rem] blur-3xl -z-10 opacity-75"></div>

                {{-- Floating KPI Chip 1 (Top Left): Invoiced Volume --}}
                <div class="hidden lg:flex items-center gap-3 absolute -top-8 -left-8 z-20 px-4 py-3 rounded-2xl bg-[#0F172A]/90 border border-white/10 shadow-2xl backdrop-blur-xl animate-bounce" style="animation-duration: 6s;">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-emerald-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="text-left">
                        <div class="text-[11px] font-medium text-slate-400">Monthly Invoiced</div>
                        <div class="text-sm font-bold text-white flex items-center gap-1.5">
                            <span>$148,250.00</span>
                            <span class="text-[10px] font-semibold text-emerald-400 bg-emerald-500/10 px-1 rounded">+34.2%</span>
                        </div>
                    </div>
                </div>

                {{-- Floating KPI Chip 2 (Top Right): Sprint Velocity --}}
                <div class="hidden lg:flex items-center gap-3 absolute -top-6 -right-8 z-20 px-4 py-3 rounded-2xl bg-[#0F172A]/90 border border-white/10 shadow-2xl backdrop-blur-xl animate-bounce" style="animation-duration: 7s;">
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/15 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <div class="text-left">
                        <div class="text-[11px] font-medium text-slate-400">Sprint 42 Velocity</div>
                        <div class="text-sm font-bold text-white flex items-center gap-1.5">
                            <span>98.4% On Schedule</span>
                            <span class="text-[10px] font-semibold text-indigo-400 bg-indigo-500/10 px-1 rounded">Ahead</span>
                        </div>
                    </div>
                </div>

                {{-- Floating KPI Chip 3 (Bottom Left): Institute Enrolments --}}
                <div class="hidden lg:flex items-center gap-3 absolute -bottom-6 -left-6 z-20 px-4 py-3 rounded-2xl bg-[#0F172A]/90 border border-white/10 shadow-2xl backdrop-blur-xl animate-bounce" style="animation-duration: 8s;">
                    <div class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-cyan-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                        </svg>
                    </div>
                    <div class="text-left">
                        <div class="text-[11px] font-medium text-slate-400">Enrolled Students</div>
                        <div class="text-sm font-bold text-white flex items-center gap-1.5">
                            <span>1,420 Active</span>
                            <span class="text-[10px] font-semibold text-cyan-400 bg-cyan-500/10 px-1 rounded">12 Batches</span>
                        </div>
                    </div>
                </div>

                {{-- Main Glass Browser Frame --}}
                <div class="rounded-3xl bg-[#0B0F19]/90 border border-white/10 p-2 sm:p-3 shadow-[0_25px_60px_-15px_rgba(0,0,0,0.8)] backdrop-blur-2xl overflow-hidden">
                    
                    {{-- Window Header Bar --}}
                    <div class="flex items-center justify-between px-3 sm:px-4 py-2.5 border-b border-white/[0.08] bg-[#090D16]/60 rounded-t-2xl">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-rose-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-amber-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-emerald-500/80"></span>
                            <span class="ml-3 hidden sm:inline-block text-xs text-slate-400 font-mono">myoffice-erp.internal/dashboard</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                Live Sync Active
                            </span>
                        </div>
                    </div>

                    {{-- Mockup ERP Interface Grid --}}
                    <div class="p-4 sm:p-6 bg-[#070A11] rounded-b-2xl text-left font-sans">
                        
                        {{-- Top Nav inside ERP --}}
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-5 border-b border-white/[0.06]">
                            <div>
                                <h3 class="text-lg font-bold text-white">Universal Operations Dashboard</h3>
                                <p class="text-xs text-slate-400">Overview of Software Agency Projects &amp; Training Batches</p>
                            </div>
                            <div class="flex items-center gap-2 self-start sm:self-auto">
                                <span class="text-xs px-3 py-1.5 rounded-lg bg-indigo-500/15 text-indigo-300 border border-indigo-500/30 font-medium">
                                    Software House Branch
                                </span>
                                <span class="text-xs px-3 py-1.5 rounded-lg bg-cyan-500/15 text-cyan-300 border border-cyan-500/30 font-medium">
                                    Academy Campus 1
                                </span>
                            </div>
                        </div>

                        {{-- Metric Cards inside mockup --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-5">
                            <div class="p-4 rounded-xl bg-white/[0.02] border border-white/[0.06] hover:border-indigo-500/30 transition-all">
                                <div class="text-xs text-slate-400 font-medium">Active Client Projects</div>
                                <div class="text-2xl font-bold text-white mt-1">18 Projects</div>
                                <div class="text-xs text-indigo-400 mt-2 flex items-center gap-1 font-medium">
                                    <span>↑ 4 in QA Review</span>
                                </div>
                            </div>

                            <div class="p-4 rounded-xl bg-white/[0.02] border border-white/[0.06] hover:border-indigo-500/30 transition-all">
                                <div class="text-xs text-slate-400 font-medium">Today's Billable Hours</div>
                                <div class="text-2xl font-bold text-white mt-1">142.5 hrs</div>
                                <div class="text-xs text-emerald-400 mt-2 flex items-center gap-1 font-medium">
                                    <span>92% team utilization</span>
                                </div>
                            </div>

                            <div class="p-4 rounded-xl bg-white/[0.02] border border-white/[0.06] hover:border-cyan-500/30 transition-all">
                                <div class="text-xs text-slate-400 font-medium">Batch Attendance Today</div>
                                <div class="text-2xl font-bold text-white mt-1">94.8%</div>
                                <div class="text-xs text-cyan-400 mt-2 flex items-center gap-1 font-medium">
                                    <span>8 RFID terminals synced</span>
                                </div>
                            </div>

                            <div class="p-4 rounded-xl bg-white/[0.02] border border-white/[0.06] hover:border-cyan-500/30 transition-all">
                                <div class="text-xs text-slate-400 font-medium">Pending Fee Challans</div>
                                <div class="text-2xl font-bold text-white mt-1">$12,400</div>
                                <div class="text-xs text-amber-400 mt-2 flex items-center gap-1 font-medium">
                                    <span>23 reminders dispatched</span>
                                </div>
                            </div>
                        </div>

                        {{-- Dual Columns inside mockup: Software House vs Training Academy --}}
                        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 mt-5">
                            
                            {{-- Software House Sprint Activity --}}
                            <div class="lg:col-span-7 p-4 sm:p-5 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2.5 h-2.5 rounded-full bg-indigo-500"></div>
                                        <span class="text-sm font-bold text-white">Active Sprints &amp; Deliverables</span>
                                    </div>
                                    <span class="text-xs text-slate-400">Sprint 14 (Day 8 of 14)</span>
                                </div>

                                <div class="space-y-3">
                                    <div class="p-3 rounded-lg bg-white/[0.02] border border-white/[0.04] flex items-center justify-between">
                                        <div>
                                            <div class="text-xs font-semibold text-slate-200">Fintech Banking Mobile App</div>
                                            <div class="text-[11px] text-slate-400">Client: Alpha Global Inc. • 6 devs assigned</div>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-xs font-bold text-emerald-400">85% Complete</span>
                                            <div class="w-24 h-1.5 bg-slate-800 rounded-full mt-1 overflow-hidden">
                                                <div class="w-[85%] h-full bg-emerald-500 rounded-full"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="p-3 rounded-lg bg-white/[0.02] border border-white/[0.04] flex items-center justify-between">
                                        <div>
                                            <div class="text-xs font-semibold text-slate-200">AI Medical Image Classifier</div>
                                            <div class="text-[11px] text-slate-400">Client: MedCore Labs • Milestone 2 Signoff</div>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-xs font-bold text-indigo-400">62% Complete</span>
                                            <div class="w-24 h-1.5 bg-slate-800 rounded-full mt-1 overflow-hidden">
                                                <div class="w-[62%] h-full bg-indigo-500 rounded-full"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Training Institute Batch Status --}}
                            <div class="lg:col-span-5 p-4 sm:p-5 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2.5 h-2.5 rounded-full bg-cyan-400"></div>
                                        <span class="text-sm font-bold text-white">Institute Batches (Ongoing)</span>
                                    </div>
                                    <span class="text-xs text-slate-400">Fall 2026</span>
                                </div>

                                <div class="space-y-2.5">
                                    <div class="flex items-center justify-between text-xs py-2 border-b border-white/[0.04]">
                                        <span class="font-medium text-slate-300">Full-Stack MERN (Batch 12)</span>
                                        <span class="text-cyan-300 font-semibold">28 Students</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs py-2 border-b border-white/[0.04]">
                                        <span class="font-medium text-slate-300">Python &amp; Generative AI (Batch 04)</span>
                                        <span class="text-cyan-300 font-semibold">32 Students</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs py-2">
                                        <span class="font-medium text-slate-300">Cybersecurity Bootcamp (Batch 08)</span>
                                        <span class="text-cyan-300 font-semibold">24 Students</span>
                                    </div>
                                </div>
                            </div>

                        </div>

                    </div>
                </div>

            </div>

        </div>
    </section>

    {{-- =========================================================================
         3. CORE SOLUTIONS & FEATURES GRID (Split by Persona)
         ========================================================================= --}}
    <section id="solutions" class="py-24 sm:py-32 relative border-t border-white/[0.08] bg-[#070A12]">
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <div class="text-center max-w-3xl mx-auto">
                <span class="text-xs font-bold uppercase tracking-wider text-indigo-400 bg-indigo-500/10 px-3 py-1 rounded-full border border-indigo-500/20">
                    Dual Persona Architecture
                </span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight mt-4">
                    Tailored for Software Houses &amp; Training Institutes
                </h2>
                <p class="text-base sm:text-lg text-slate-300 mt-4 leading-relaxed">
                    Most ERPs force you into a generic mold. MyOffice ERP was engineered from the ground up for organizations that ship software for clients and train the next generation of engineers under one roof.
                </p>
            </div>

            {{-- Interactive Persona Switcher Tabs --}}
            <div class="mt-10 flex justify-center">
                <div class="inline-flex p-1.5 rounded-2xl bg-white/[0.04] border border-white/10 backdrop-blur-md">
                    <button 
                        type="button"
                        @click="activePersona = 'software'"
                        :class="activePersona === 'software' ? 'bg-gradient-to-r from-indigo-600 to-indigo-500 text-white shadow-lg shadow-indigo-500/30' : 'text-slate-400 hover:text-white'"
                        class="px-6 py-3 rounded-xl text-sm font-bold transition-all duration-300 flex items-center gap-2.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                        </svg>
                        <span>For Software Houses &amp; Agencies</span>
                    </button>

                    <button 
                        type="button"
                        @click="activePersona = 'institute'"
                        :class="activePersona === 'institute' ? 'bg-gradient-to-r from-cyan-600 to-cyan-500 text-white shadow-lg shadow-cyan-500/30' : 'text-slate-400 hover:text-white'"
                        class="px-6 py-3 rounded-xl text-sm font-bold transition-all duration-300 flex items-center gap-2.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                        </svg>
                        <span>For Training Institutes &amp; Academies</span>
                    </button>
                </div>
            </div>

            {{-- PERSONA 1: Software House Features Grid --}}
            <div 
                x-show="activePersona === 'software'" 
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="mt-14 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"
            >
                {{-- Feature 1 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-indigo-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-indigo-200 transition-colors">Sprint Boards &amp; Task Kanban</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Agile sprint management with task estimation, backlog grooming, priority tagging, and automated blocker escalation.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-indigo-400 font-semibold">
                        <span>Includes Git &amp; Jira sync</span>
                    </div>
                </div>

                {{-- Feature 2 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-indigo-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-indigo-200 transition-colors">Timesheets &amp; Billable Hours</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Granular time-tracking for engineers and designers. Distinguish billable client hours from internal R&amp;D with instant timesheet approvals.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-indigo-400 font-semibold">
                        <span>Zero intrusive spyware</span>
                    </div>
                </div>

                {{-- Feature 3 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-indigo-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-indigo-200 transition-colors">Multi-Currency Invoicing &amp; Tax</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Generate professional PDF invoices with multi-currency conversions (USD, EUR, GBP, PKR), automated GST/VAT tax breakdown, and Stripe integration.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-indigo-400 font-semibold">
                        <span>Automated payment reminders</span>
                    </div>
                </div>

                {{-- Feature 4 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-indigo-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-indigo-200 transition-colors">White-Label Client Portal</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Offer clients a branded login where they can view project burn-down charts, approve milestones, download invoices, and test staging releases.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-indigo-400 font-semibold">
                        <span>Custom domain support</span>
                    </div>
                </div>

                {{-- Feature 5 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-indigo-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-indigo-200 transition-colors">Project Margin &amp; Profitability</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Calculate exact developer cost per hour against invoice milestones. Receive early alerts if project scope creeps past budgeted profit margins.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-indigo-400 font-semibold">
                        <span>Real-time EBITDA projections</span>
                    </div>
                </div>

                {{-- Feature 6 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-indigo-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-indigo-200 transition-colors">Enterprise RBAC &amp; Audit Trail</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Fine-grained role permissions for Junior Devs, Project Managers, Accountants, and CTOs. Every code link and financial action is immutable and logged.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-indigo-400 font-semibold">
                        <span>SOC2 &amp; GDPR compliant</span>
                    </div>
                </div>
            </div>

            {{-- PERSONA 2: Training Institute Features Grid --}}
            <div 
                x-show="activePersona === 'institute'" 
                x-cloak
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="mt-14 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"
            >
                {{-- Feature 1 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-cyan-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 group-hover:bg-cyan-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-cyan-200 transition-colors">Batch Management &amp; Timetables</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Automated clash-free timetable builder for physical labs, virtual classes, and trainer schedules. Accommodate morning, evening, and weekend cohorts.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-cyan-400 font-semibold">
                        <span>Automated trainer substitute alerts</span>
                    </div>
                </div>

                {{-- Feature 2 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-cyan-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 group-hover:bg-cyan-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-cyan-200 transition-colors">Admissions &amp; Lead Funnel</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Embeddable online admission forms with automated lead scoring, admission test evaluation, document verification, and seat allocation workflows.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-cyan-400 font-semibold">
                        <span>WhatsApp &amp; SMS inquiry alerts</span>
                    </div>
                </div>

                {{-- Feature 3 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-cyan-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 group-hover:bg-cyan-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-cyan-200 transition-colors">Automated Fee Vouchers &amp; Challans</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Generate 3-part bank fee vouchers in 1 click. Support installment plans, early-bird discounts, late fee penalty triggers, and automated receipt generation.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-cyan-400 font-semibold">
                        <span>Bank reconciliation support</span>
                    </div>
                </div>

                {{-- Feature 4 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-cyan-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 group-hover:bg-cyan-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-cyan-200 transition-colors">RFID &amp; Biometric Attendance</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Direct hardware integration with ZKTeco and Hikvision biometric devices. Real-time absentee notifications sent automatically to students and guardians.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-cyan-400 font-semibold">
                        <span>Defaulter threshold warning</span>
                    </div>
                </div>

                {{-- Feature 5 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-cyan-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 group-hover:bg-cyan-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-cyan-200 transition-colors">Course Syllabus &amp; LMS Grading</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Modular curriculum delivery, practical programming assignments, lecture slide hosting, quiz scores, and student project portfolio reviews.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-cyan-400 font-semibold">
                        <span>Direct GitHub repo submissions</span>
                    </div>
                </div>

                {{-- Feature 6 --}}
                <div class="p-7 rounded-2xl bg-white/[0.02] border border-white/[0.07] hover:border-cyan-500/40 hover:bg-white/[0.04] transition-all duration-300 group">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 group-hover:bg-cyan-500 group-hover:text-white transition-all duration-300 mb-5">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-white group-hover:text-cyan-200 transition-colors">Verifiable Certificates &amp; QR</h3>
                    <p class="text-sm text-slate-300 mt-2.5 leading-relaxed">
                        Issue cryptographic digital completion certificates. Employers can scan the QR code to verify student credentials and transcripts instantly on your portal.
                    </p>
                    <div class="mt-4 flex items-center gap-2 text-xs text-cyan-400 font-semibold">
                        <span>Tamper-proof online verification</span>
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- =========================================================================
         4. MODERN PRICING PLANS (Crucial Section)
         ========================================================================= --}}
    <section id="pricing" class="py-24 sm:py-32 relative border-t border-white/[0.08] bg-[#090D16]">
        
        {{-- Background Light Effect --}}
        <div class="absolute inset-0 bg-tech-grid opacity-20 pointer-events-none"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[700px] h-[500px] bg-indigo-600/10 rounded-full blur-[160px] pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            
            <div class="text-center max-w-3xl mx-auto">
                <span class="text-xs font-bold uppercase tracking-wider text-indigo-400 bg-indigo-500/10 px-3 py-1 rounded-full border border-indigo-500/20">
                    Transparent, Scalable Pricing
                </span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight mt-4">
                    Simple Plans That Grow With Your Business
                </h2>
                <p class="text-base sm:text-lg text-slate-300 mt-4 leading-relaxed">
                    No surprise setup fees. No per-seat penalties. Switch between monthly and annual billing anytime.
                </p>

                {{-- Interactive Monthly vs Yearly Toggle with Save 20% Badge --}}
                <div class="mt-8 inline-flex items-center gap-4 p-1.5 rounded-full bg-white/[0.04] border border-white/10 backdrop-blur-md">
                    <button 
                        type="button"
                        @click="billingCycle = 'monthly'"
                        :class="billingCycle === 'monthly' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white'"
                        class="px-5 py-2 rounded-full text-xs sm:text-sm font-semibold transition-all duration-200"
                    >
                        Monthly Billing
                    </button>
                    <button 
                        type="button"
                        @click="billingCycle = 'yearly'"
                        :class="billingCycle === 'yearly' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white'"
                        class="px-5 py-2 rounded-full text-xs sm:text-sm font-semibold transition-all duration-200 flex items-center gap-2"
                    >
                        <span>Yearly Billing</span>
                        <span class="bg-cyan-500/20 text-cyan-300 text-[10px] font-bold px-2 py-0.5 rounded-full border border-cyan-400/30">
                            Save 20%
                        </span>
                    </button>
                </div>
            </div>

            {{-- 3-Tier Card Layout --}}
            <div class="mt-16 grid grid-cols-1 lg:grid-cols-3 gap-8 items-stretch">
                
                {{-- TIER 1: Starter / Academy Tier --}}
                <div class="flex flex-col justify-between p-8 rounded-3xl bg-white/[0.02] border border-white/[0.08] hover:border-white/20 transition-all duration-300 backdrop-blur-xl">
                    <div>
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-bold text-white">Starter Academy</h3>
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-white/[0.05] text-slate-300 border border-white/10">Boutique</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-2">Perfect for small IT bootcamps or early-stage agencies.</p>

                        <div class="mt-6 flex items-baseline gap-1">
                            <span class="text-4xl sm:text-5xl font-extrabold text-white" x-text="billingCycle === 'monthly' ? '$29' : '$23'">$29</span>
                            <span class="text-sm text-slate-400">/ month</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-1" x-text="billingCycle === 'monthly' ? 'Billed monthly ($29/mo)' : 'Billed annually ($276/yr)'"></p>

                        <div class="mt-8 pt-8 border-t border-white/[0.08] space-y-4">
                            <div class="text-xs font-bold uppercase tracking-wider text-slate-300">What's included:</div>
                            
                            <ul class="space-y-3 text-sm text-slate-300">
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Up to <strong>50 Active Students</strong></span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Up to <strong>5 Client Projects</strong></span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Basic Fee Vouchers &amp; Invoices</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Time Logging &amp; Attendance</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Standard Email Support (24h)</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-8 pt-4">
                        <a 
                            href="{{ $loginUrl }}" 
                            class="w-full inline-flex items-center justify-center py-3.5 px-6 rounded-xl text-sm font-semibold text-slate-200 bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 hover:border-white/20 transition-all duration-200"
                        >
                            Get Started Free
                        </a>
                    </div>
                </div>

                {{-- TIER 2: Professional / Growth Tier [HIGHLIGHTED / MOST POPULAR] --}}
                <div class="relative flex flex-col justify-between p-8 rounded-3xl bg-[#0F172A]/90 border-2 border-indigo-500 shadow-[0_0_50px_rgba(99,102,241,0.35)] backdrop-blur-2xl transform lg:-translate-y-4">
                    
                    {{-- Most Popular Floating Badge --}}
                    <div class="absolute -top-3.5 left-1/2 -translate-x-1/2 px-4 py-1 rounded-full bg-gradient-to-r from-indigo-500 via-indigo-600 to-violet-600 text-white text-[11px] font-extrabold uppercase tracking-wider shadow-lg shadow-indigo-500/50 flex items-center gap-1.5">
                        <span>✨ MOST POPULAR • BEST VALUE</span>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mt-2">
                            <h3 class="text-xl font-bold text-white">Professional Growth</h3>
                            <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">Dual-Engine</span>
                        </div>
                        <p class="text-xs text-slate-300 mt-2">Complete unified management for growing software houses &amp; academies.</p>

                        <div class="mt-6 flex items-baseline gap-1">
                            <span class="text-4xl sm:text-5xl font-extrabold text-white" x-text="billingCycle === 'monthly' ? '$79' : '$63'">$79</span>
                            <span class="text-sm text-slate-300">/ month</span>
                        </div>
                        <p class="text-xs text-indigo-300 mt-1" x-text="billingCycle === 'monthly' ? 'Billed monthly ($79/mo)' : 'Billed annually ($756/yr) — 20% Saved'"></p>

                        <div class="mt-8 pt-8 border-t border-white/[0.12] space-y-4">
                            <div class="text-xs font-bold uppercase tracking-wider text-indigo-300">Everything in Starter, plus:</div>
                            
                            <ul class="space-y-3 text-sm text-slate-200">
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span><strong>Unlimited Students &amp; Batches</strong></span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span><strong>Unlimited Projects &amp; Sprints</strong></span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Full Client &amp; Student Self-Service Portals</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Automated Multi-Currency Billing &amp; Invoices</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Biometric &amp; RFID Attendance Terminal Sync</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Priority 24/7 Slack &amp; Live Chat Support</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-8 pt-4">
                        <a 
                            href="{{ $loginUrl }}" 
                            class="w-full inline-flex items-center justify-center py-4 px-6 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-500 via-indigo-600 to-violet-600 hover:from-indigo-400 hover:via-indigo-500 hover:to-violet-500 shadow-[0_0_25px_rgba(99,102,241,0.5)] transition-all duration-300"
                        >
                            Start 14-Day Free Trial
                        </a>
                    </div>
                </div>

                {{-- TIER 3: Enterprise Tier --}}
                <div class="flex flex-col justify-between p-8 rounded-3xl bg-white/[0.02] border border-white/[0.08] hover:border-white/20 transition-all duration-300 backdrop-blur-xl">
                    <div>
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-bold text-white">Enterprise Scale</h3>
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-white/[0.05] text-slate-300 border border-white/10">Tailored</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-2">For multi-campus institutes &amp; large-scale dev agencies.</p>

                        <div class="mt-6 flex items-baseline gap-1">
                            <span class="text-4xl sm:text-5xl font-extrabold text-white">Custom</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">Starting from $199/mo with annual SLAs.</p>

                        <div class="mt-8 pt-8 border-t border-white/[0.08] space-y-4">
                            <div class="text-xs font-bold uppercase tracking-wider text-slate-300">Enterprise Capabilities:</div>
                            
                            <ul class="space-y-3 text-sm text-slate-300">
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Multi-Branch &amp; Franchise Partitioning</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Dedicated Private Cloud or On-Prem VPS</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Full White-Labeling &amp; Custom Domain</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Custom Workflows, Webhooks &amp; REST API</span>
                                </li>
                                <li class="flex items-center gap-3">
                                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    <span>Dedicated Account Manager &amp; 99.9% SLA</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-8 pt-4">
                        <a 
                            href="mailto:{{ $contactEmail }}?subject=Enterprise%20Plan%20Inquiry" 
                            class="w-full inline-flex items-center justify-center py-3.5 px-6 rounded-xl text-sm font-semibold text-slate-200 bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 hover:border-white/20 transition-all duration-200"
                        >
                            Contact Enterprise Sales
                        </a>
                    </div>
                </div>

            </div>

        </div>
    </section>

    {{-- =========================================================================
         5. FAQ ACCORDION SECTION
         ========================================================================= --}}
    <section id="faqs" class="py-24 sm:py-32 relative border-t border-white/[0.08] bg-[#070A12]">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <div class="text-center">
                <span class="text-xs font-bold uppercase tracking-wider text-indigo-400 bg-indigo-500/10 px-3 py-1 rounded-full border border-indigo-500/20">
                    Frequently Asked Questions
                </span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight mt-4">
                    Everything You Need to Know
                </h2>
                <p class="text-base sm:text-lg text-slate-300 mt-4 leading-relaxed">
                    Have more questions? Reach out to our technical architecture team anytime.
                </p>
            </div>

            <div class="mt-12 space-y-4">
                
                {{-- FAQ 1 --}}
                <div class="rounded-2xl bg-white/[0.02] border border-white/[0.08] overflow-hidden transition-all duration-200">
                    <button 
                        type="button"
                        @click="activeFaq = activeFaq === 1 ? null : 1"
                        class="w-full py-5 px-6 text-left flex items-center justify-between gap-4 focus:outline-none"
                    >
                        <span class="text-base sm:text-lg font-bold text-white">How does MyOffice ERP unify software development with student training?</span>
                        <span class="w-8 h-8 rounded-full bg-white/[0.05] flex items-center justify-center text-indigo-400 shrink-0">
                            <svg class="w-4 h-4 transform transition-transform duration-200" :class="activeFaq === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </span>
                    </button>
                    <div x-show="activeFaq === 1" x-collapse x-cloak class="px-6 pb-6 text-sm text-slate-300 leading-relaxed border-t border-white/[0.04] pt-4">
                        MyOffice ERP is purpose-built for dual-model tech organizations. It houses two dedicated engines sharing one central database: one engine handles sprints, client milestones, and developer billing, while the other manages batches, student admissions, fee challans, and attendance. You get unified reporting on institute revenue and agency profits side-by-side.
                    </div>
                </div>

                {{-- FAQ 2 --}}
                <div class="rounded-2xl bg-white/[0.02] border border-white/[0.08] overflow-hidden transition-all duration-200">
                    <button 
                        type="button"
                        @click="activeFaq = activeFaq === 2 ? null : 2"
                        class="w-full py-5 px-6 text-left flex items-center justify-between gap-4 focus:outline-none"
                    >
                        <span class="text-base sm:text-lg font-bold text-white">Can students and clients access separate, secure portals?</span>
                        <span class="w-8 h-8 rounded-full bg-white/[0.05] flex items-center justify-center text-indigo-400 shrink-0">
                            <svg class="w-4 h-4 transform transition-transform duration-200" :class="activeFaq === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </span>
                    </button>
                    <div x-show="activeFaq === 2" x-collapse x-cloak class="px-6 pb-6 text-sm text-slate-300 leading-relaxed border-t border-white/[0.04] pt-4">
                        Yes! Software clients log into a dedicated Client Portal where they only see project deliverables, time reports, and invoices. Students log into an LMS Student Portal where they view course syllabus, fee vouchers, and attendance records. Strict role-based isolation guarantees clients never see academy data and vice versa.
                    </div>
                </div>

                {{-- FAQ 3 --}}
                <div class="rounded-2xl bg-white/[0.02] border border-white/[0.08] overflow-hidden transition-all duration-200">
                    <button 
                        type="button"
                        @click="activeFaq = activeFaq === 3 ? null : 3"
                        class="w-full py-5 px-6 text-left flex items-center justify-between gap-4 focus:outline-none"
                    >
                        <span class="text-base sm:text-lg font-bold text-white">How does fee voucher generation and bank reconciliation work?</span>
                        <span class="w-8 h-8 rounded-full bg-white/[0.05] flex items-center justify-center text-indigo-400 shrink-0">
                            <svg class="w-4 h-4 transform transition-transform duration-200" :class="activeFaq === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </span>
                    </button>
                    <div x-show="activeFaq === 3" x-collapse x-cloak class="px-6 pb-6 text-sm text-slate-300 leading-relaxed border-t border-white/[0.04] pt-4">
                        The system automatically generates standardized 3-copy bank challans (Student Copy, Bank Copy, Institute Copy) with barcodes or QR codes. When students pay via bank transfer, JazzCash/EasyPaisa, or direct deposit, accountants can upload bank statements for automated reconciliation or mark records paid in one click.
                    </div>
                </div>

                {{-- FAQ 4 --}}
                <div class="rounded-2xl bg-white/[0.02] border border-white/[0.08] overflow-hidden transition-all duration-200">
                    <button 
                        type="button"
                        @click="activeFaq = activeFaq === 4 ? null : 4"
                        class="w-full py-5 px-6 text-left flex items-center justify-between gap-4 focus:outline-none"
                    >
                        <span class="text-base sm:text-lg font-bold text-white">Can we migrate our existing student data and ongoing projects?</span>
                        <span class="w-8 h-8 rounded-full bg-white/[0.05] flex items-center justify-center text-indigo-400 shrink-0">
                            <svg class="w-4 h-4 transform transition-transform duration-200" :class="activeFaq === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </span>
                    </button>
                    <div x-show="activeFaq === 4" x-collapse x-cloak class="px-6 pb-6 text-sm text-slate-300 leading-relaxed border-t border-white/[0.04] pt-4">
                        Absolutely. We provide high-speed CSV/Excel bulk import utilities for students, past fee registers, course catalogs, client accounts, and project backlogs. Our onboarding engineering team also offers complimentary migration assistance on Professional and Enterprise plans.
                    </div>
                </div>

                {{-- FAQ 5 --}}
                <div class="rounded-2xl bg-white/[0.02] border border-white/[0.08] overflow-hidden transition-all duration-200">
                    <button 
                        type="button"
                        @click="activeFaq = activeFaq === 5 ? null : 5"
                        class="w-full py-5 px-6 text-left flex items-center justify-between gap-4 focus:outline-none"
                    >
                        <span class="text-base sm:text-lg font-bold text-white">What biometric attendance hardware is supported?</span>
                        <span class="w-8 h-8 rounded-full bg-white/[0.05] flex items-center justify-center text-indigo-400 shrink-0">
                            <svg class="w-4 h-4 transform transition-transform duration-200" :class="activeFaq === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </span>
                    </button>
                    <div x-show="activeFaq === 5" x-collapse x-cloak class="px-6 pb-6 text-sm text-slate-300 leading-relaxed border-t border-white/[0.04] pt-4">
                        We support all industry-standard ZKTeco, Hikvision, and Realand biometric fingerprint &amp; RFID readers through our lightweight local gateway service. Punch logs sync instantly into student course attendance registers and staff payroll sheets.
                    </div>
                </div>

                {{-- FAQ 6 --}}
                <div class="rounded-2xl bg-white/[0.02] border border-white/[0.08] overflow-hidden transition-all duration-200">
                    <button 
                        type="button"
                        @click="activeFaq = activeFaq === 6 ? null : 6"
                        class="w-full py-5 px-6 text-left flex items-center justify-between gap-4 focus:outline-none"
                    >
                        <span class="text-base sm:text-lg font-bold text-white">Can we host MyOffice ERP on our own private cloud or server?</span>
                        <span class="w-8 h-8 rounded-full bg-white/[0.05] flex items-center justify-center text-indigo-400 shrink-0">
                            <svg class="w-4 h-4 transform transition-transform duration-200" :class="activeFaq === 6 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </span>
                    </button>
                    <div x-show="activeFaq === 6" x-collapse x-cloak class="px-6 pb-6 text-sm text-slate-300 leading-relaxed border-t border-white/[0.04] pt-4">
                        Yes. For organizations with strict data residency compliance, our Enterprise Tier supports deployment on your own AWS, Hetzner, DigitalOcean, or on-premises servers with full Docker containerization and automated daily backups.
                    </div>
                </div>

            </div>

        </div>
    </section>

    {{-- =========================================================================
         6. HIGH-CONVERTING PRE-FOOTER CTA BANNER
         ========================================================================= --}}
    <section class="py-20 sm:py-28 relative overflow-hidden">
        
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            
            <div class="relative rounded-3xl p-8 sm:p-14 lg:p-16 bg-gradient-to-br from-indigo-950/80 via-[#0B0F19] to-violet-950/80 border border-indigo-500/30 shadow-[0_0_60px_rgba(99,102,241,0.25)] overflow-hidden">
                
                {{-- Decorative Glow Orbs inside Banner --}}
                <div class="absolute -top-24 -right-24 w-72 h-72 rounded-full bg-cyan-500/20 blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-24 -left-24 w-72 h-72 rounded-full bg-indigo-500/25 blur-3xl pointer-events-none"></div>

                <div class="relative z-10 max-w-3xl mx-auto text-center">
                    <span class="text-xs font-bold uppercase tracking-wider text-cyan-300 bg-cyan-500/10 px-3 py-1 rounded-full border border-cyan-400/20">
                        ⚡ Instant 14-Day Free Access
                    </span>
                    
                    <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight mt-5 leading-tight">
                        Ready to streamline your tech business and training academy?
                    </h2>
                    
                    <p class="text-base sm:text-lg text-slate-300 mt-4 leading-relaxed">
                        Join 250+ tech institutes and dev agencies automating their operations. Launch your workspace in under 2 minutes.
                    </p>

                    {{-- Email Input Capture with instant state feedback --}}
                    <div class="mt-8 max-w-md mx-auto">
                        <form @submit.prevent="submitNewsletter" class="flex flex-col sm:flex-row gap-2.5">
                            <input 
                                type="email" 
                                x-model="emailInput"
                                required
                                placeholder="Enter your work email address..." 
                                class="flex-1 px-4 py-3.5 rounded-xl bg-white/[0.07] border border-white/15 text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm backdrop-blur-md"
                            >
                            <button 
                                type="submit" 
                                class="px-6 py-3.5 rounded-xl bg-gradient-to-r from-indigo-500 via-indigo-600 to-violet-600 hover:from-indigo-400 hover:via-indigo-500 hover:to-violet-500 text-white font-bold text-sm shadow-[0_0_20px_rgba(99,102,241,0.4)] transition-all shrink-0 active:scale-95"
                            >
                                Get Instant Access
                            </button>
                        </form>

                        {{-- Instant submission feedback badge --}}
                        <div x-show="emailSubmitted" x-cloak class="mt-3 text-xs font-semibold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 py-1.5 px-3 rounded-lg">
                            🎉 Access link dispatched! Check your inbox shortly.
                        </div>

                        <div class="mt-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs text-slate-400">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                14-Day Free Trial
                            </span>
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                No Credit Card Required
                            </span>
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Cancel Anytime
                            </span>
                        </div>
                    </div>

                </div>

            </div>

        </div>
    </section>

    {{-- =========================================================================
         7. FOOTER
         ========================================================================= --}}
    <footer id="contact" class="border-t border-white/[0.08] bg-[#05070D] pt-16 pb-12 relative text-sm text-slate-400">
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-10 lg:gap-8 pb-12 border-b border-white/[0.08]">
                
                {{-- Col 1: Brand details & bio --}}
                <div class="lg:col-span-2 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-white">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                            </svg>
                        </div>
                        <span class="text-lg font-bold text-white tracking-tight">{{ $companyName }}</span>
                    </div>

                    <p class="text-sm text-slate-300 max-w-sm leading-relaxed">
                        {{ $tagline }} The definitive operating platform for software development companies and vocational IT institutes.
                    </p>

                    <div class="flex items-center gap-3 pt-2">
                        {{-- WhatsApp --}}
                        <a href="https://wa.me/{{ preg_replace('/[^\d]/', '', $whatsapp) }}" target="_blank" rel="noopener noreferrer" class="w-9 h-9 rounded-lg bg-white/[0.04] border border-white/10 flex items-center justify-center text-slate-400 hover:text-emerald-400 hover:border-emerald-500/40 transition-colors" title="WhatsApp Support">
                            <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.007c.107.005.249-.041.39.299.144.35.491 1.199.534 1.286.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.087-.179.18-.077.355.101.173.449.741.964 1.201.662.591 1.221.774 1.394.861.173.086.275.072.376-.044.101-.116.433-.506.549-.68.116-.173.231-.145.39-.087s1.011.477 1.184.564.289.13.332.202c.044.073.044.421-.1 1.026z"/></svg>
                        </a>
                        {{-- Phone --}}
                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $contactPhone) }}" class="w-9 h-9 rounded-lg bg-white/[0.04] border border-white/10 flex items-center justify-center text-slate-400 hover:text-indigo-400 hover:border-indigo-500/40 transition-colors" title="Direct Phone">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                        </a>
                        {{-- Email --}}
                        <a href="mailto:{{ $contactEmail }}" class="w-9 h-9 rounded-lg bg-white/[0.04] border border-white/10 flex items-center justify-center text-slate-400 hover:text-cyan-400 hover:border-cyan-500/40 transition-colors" title="Email Us">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                        </a>
                    </div>
                </div>

                {{-- Col 2: Solutions --}}
                <div class="space-y-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-white">Solutions</div>
                    <ul class="space-y-2 text-xs text-slate-300">
                        <li><a href="#solutions" class="hover:text-white transition-colors">Software House ERP</a></li>
                        <li><a href="#solutions" class="hover:text-white transition-colors">Training Institute CRM</a></li>
                        <li><a href="#solutions" class="hover:text-white transition-colors">Sprint Boards &amp; Tasks</a></li>
                        <li><a href="#solutions" class="hover:text-white transition-colors">Fee Vouchers &amp; Challans</a></li>
                        <li><a href="#solutions" class="hover:text-white transition-colors">Biometric Attendance</a></li>
                        <li><a href="#solutions" class="hover:text-white transition-colors">Multi-Branch Management</a></li>
                    </ul>
                </div>

                {{-- Col 3: Product & Resources --}}
                <div class="space-y-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-white">Product &amp; Trust</div>
                    <ul class="space-y-2 text-xs text-slate-300">
                        <li><a href="#features" class="hover:text-white transition-colors">Features Directory</a></li>
                        <li><a href="#pricing" class="hover:text-white transition-colors">Pricing Plans</a></li>
                        <li><a href="#faqs" class="hover:text-white transition-colors">FAQs</a></li>
                        <li><a href="{{ $loginUrl }}" class="hover:text-white transition-colors">Staff Login</a></li>
                        <li><a href="{{ $loginUrl }}" class="hover:text-white transition-colors">Student Portal</a></li>
                        <li><a href="{{ $loginUrl }}" class="hover:text-white transition-colors">Client Portal</a></li>
                    </ul>
                </div>

                {{-- Col 4: Contact & Operations --}}
                <div class="space-y-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-white">Headquarters</div>
                    <p class="text-xs text-slate-300 leading-relaxed">
                        {{ $contactAddress }}
                    </p>
                    <div class="pt-1 text-xs text-slate-300 space-y-1">
                        <div><strong>Phone:</strong> {{ $contactPhone }}</div>
                        <div><strong>Email:</strong> {{ $contactEmail }}</div>
                        <div><strong>Hours:</strong> {{ $businessHours }}</div>
                    </div>
                </div>

            </div>

            {{-- Bottom Bar: Copyright & Policies --}}
            <div class="pt-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-400">
                <div>
                    &copy; {{ date('Y') }} {{ $companyName }}. All rights reserved.
                </div>
                
                <div class="flex items-center gap-6">
                    <span class="inline-flex items-center gap-1.5 text-emerald-400 font-medium">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        All Systems Operational
                    </span>
                    <a href="{{ url('/privacy-policy') }}" class="hover:text-slate-300 transition-colors">Privacy Policy</a>
                    <a href="{{ url('/terms-of-service') }}" class="hover:text-slate-300 transition-colors">Terms of Service</a>
                </div>
            </div>

        </div>
    </footer>

    {{-- =========================================================================
         INTERACTIVE DEMO MODAL
         ========================================================================= --}}
    <div 
        x-show="demoModalOpen" 
        x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-xl"
    >
        <div 
            @click.away="demoModalOpen = false"
            class="relative w-full max-w-4xl rounded-3xl bg-[#0B0F19] border border-white/15 p-6 sm:p-8 shadow-2xl text-left"
        >
            <div class="flex items-center justify-between pb-4 border-b border-white/10">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-indigo-500"></span>
                    <h3 class="text-lg font-bold text-white">Interactive Walkthrough • MyOffice ERP</h3>
                </div>
                <button 
                    type="button" 
                    @click="demoModalOpen = false"
                    class="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition-colors"
                >
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="mt-6 space-y-4">
                <div class="aspect-video w-full rounded-2xl bg-[#070A11] border border-white/10 flex flex-col items-center justify-center p-8 text-center relative overflow-hidden">
                    <div class="absolute inset-0 bg-gradient-to-tr from-indigo-500/10 via-transparent to-cyan-500/10 pointer-events-none"></div>
                    
                    <div class="w-16 h-16 rounded-2xl bg-indigo-600/20 border border-indigo-500/40 flex items-center justify-center text-indigo-400 mb-4 shadow-lg shadow-indigo-500/20">
                        <svg class="w-8 h-8 fill-current ml-1" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
                    </div>

                    <h4 class="text-xl font-bold text-white">Interactive Sandbox Preview</h4>
                    <p class="text-sm text-slate-300 max-w-md mt-2">
                        Experience the dual dashboard live with simulated students, active Jira-synced sprints, and real-time bank challan reconciliation.
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3 justify-center">
                        <a 
                            href="{{ $loginUrl }}" 
                            class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 to-violet-600 text-white font-bold text-sm shadow-lg hover:shadow-indigo-500/50 transition-all"
                        >
                            Open Live Sandbox Login
                        </a>
                        <button 
                            type="button"
                            @click="demoModalOpen = false"
                            class="px-5 py-2.5 rounded-xl bg-white/[0.05] border border-white/10 text-slate-300 hover:text-white text-sm font-semibold"
                        >
                            Back to Landing Page
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Back to top floating button --}}
    <button 
        x-show="scrolled" 
        x-cloak 
        x-transition.opacity 
        @click="window.scrollTo({ top: 0, behavior: 'smooth' })" 
        class="fixed bottom-6 right-6 z-40 p-3 rounded-full bg-indigo-600/90 text-white shadow-xl hover:bg-indigo-500 transition-all duration-200 border border-indigo-400/30"
        aria-label="Scroll back to top"
    >
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18" />
        </svg>
    </button>

</body>
</html>
