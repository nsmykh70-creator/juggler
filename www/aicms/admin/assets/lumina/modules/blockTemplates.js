/**
 * Lumina Block Templates — v3
 * ============================
 * Each template:
 *   - Uses Tailwind CSS v3 utility classes only (works with Play CDN,
 *     and with the bundled tailwind.local.css + tailwind-shim.css for
 *     offline rendering).
 *   - First wrapper div carries the class "lumina-layout-block" so the
 *     editor can select/move/animate the whole block.
 *   - Uses real Unsplash / Picsum image URLs (royalty-free) instead
 *     of placeholder boxes — so the preview looks like a finished page.
 *   - Real video embeds use a YouTube <iframe> so they actually play
 *     in the editor and on the live site.
 */

export const BLOCK_TEMPLATES = [

    // =====================================================================
    // HERO SECTIONS
    // =====================================================================
    {
        name: 'Hero SaaS',
        category: 'Marketing',
        icon: 'layout',
        desc: 'Centered hero with gradient headline and dual CTA',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-20 px-6 text-center font-sans">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-indigo-50 text-indigo-600 mb-6">New Release v3.0</span>
            <h1 class="text-4xl md:text-6xl font-extrabold text-slate-900 mb-6 tracking-tight leading-tight">Build faster with <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 to-violet-600">Lumina Editor</span></h1>
            <p class="text-xl text-slate-600 max-w-2xl mx-auto mb-10 leading-relaxed">The ultimate block-based editor for modern web applications. Drag, drop, animate — ship in minutes, not days.</p>
            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <a href="#" class="inline-flex justify-center items-center px-8 py-3.5 text-base font-semibold text-white bg-indigo-600 rounded-full hover:bg-indigo-700 transition shadow-lg shadow-indigo-200">Get Started Free</a>
                <a href="#" class="inline-flex justify-center items-center px-8 py-3.5 text-base font-semibold text-slate-700 bg-white border border-slate-200 rounded-full hover:bg-slate-50 transition">Read the Docs</a>
            </div>
            <div class="mt-16 max-w-5xl mx-auto">
                <img src="https://images.unsplash.com/photo-1551434678-e076c223a692?auto=format&fit=crop&w=1200&q=80" alt="Dashboard preview" class="rounded-2xl shadow-2xl w-full" loading="lazy">
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Hero Split',
        category: 'Marketing',
        icon: 'layout',
        desc: 'Two-column hero: text left, photo right',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-16 px-6 grid grid-cols-1 md:grid-cols-2 gap-12 items-center font-sans">
            <div>
                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 mb-4">Trusted by 12,000+ teams</span>
                <h1 class="text-4xl md:text-5xl font-bold text-slate-900 mb-6 leading-tight">Grow your audience<br>with confidence.</h1>
                <p class="text-lg text-slate-600 mb-8 leading-relaxed">Turn your visitors into loyal customers with our automated marketing platform. Built for creators, by creators.</p>
                <div class="flex flex-wrap gap-4">
                    <button class="px-6 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">Start Free Trial</button>
                    <button class="px-6 py-3 bg-white text-slate-700 border border-slate-200 rounded-lg font-semibold hover:bg-slate-50 transition">Watch Demo</button>
                </div>
            </div>
            <div class="relative">
                <div class="absolute inset-0 bg-gradient-to-br from-blue-500 to-violet-500 rounded-3xl transform rotate-3 opacity-20"></div>
                <img src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=800&q=80" alt="Team working" class="relative rounded-3xl shadow-xl w-full object-cover aspect-[4/3]" loading="lazy">
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Hero Video BG',
        category: 'Marketing',
        icon: 'video',
        desc: 'Dark hero with gradient overlay and centered CTA',
        html: `<div class="lumina-layout-block w-full py-32 px-6 bg-slate-900 text-center font-sans relative overflow-hidden rounded-2xl">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-900/40 via-slate-900 to-violet-900/40"></div>
            <div class="relative z-10 max-w-3xl mx-auto">
                <h1 class="text-4xl md:text-6xl font-bold text-white mb-6 tracking-tight">Cinema Quality<br><span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-violet-400">Results</span></h1>
                <p class="text-xl text-slate-300 mb-10 max-w-2xl mx-auto">Create stunning videos directly in your browser. No software to install, no learning curve.</p>
                <button class="px-8 py-4 bg-white text-slate-900 rounded-full font-bold hover:bg-slate-100 transition shadow-2xl inline-flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                    Watch Demo
                </button>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Hero Minimal',
        category: 'Marketing',
        icon: 'layout',
        desc: 'Typography-first hero with thin divider',
        html: `<div class="lumina-layout-block w-full max-w-4xl mx-auto py-24 px-6 text-center font-sans">
            <h1 class="text-5xl md:text-7xl font-bold text-slate-900 tracking-tighter mb-8">Less is more.</h1>
            <p class="text-2xl text-slate-500 font-light mb-12 leading-relaxed">Focus on the essential.<br>Eliminate the noise.<br>Build something beautiful.</p>
            <div class="w-16 h-1 bg-slate-900 mx-auto rounded-full"></div>
            <div class="mt-10 flex justify-center gap-6">
                <a href="#" class="text-slate-900 font-semibold border-b-2 border-slate-900 pb-1">Get Started →</a>
                <a href="#" class="text-slate-500 hover:text-slate-900 transition">Learn More</a>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Hero App Store',
        category: 'Marketing',
        icon: 'smartphone',
        desc: 'Mobile app showcase with phone mockup',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-16 px-6 grid grid-cols-1 md:grid-cols-2 gap-12 items-center font-sans">
            <div>
                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-pink-50 text-pink-600 mb-4">Now on iOS & Android</span>
                <h1 class="text-4xl md:text-5xl font-bold text-slate-900 mb-6 leading-tight">Your life,<br>organized.</h1>
                <p class="text-lg text-slate-600 mb-8 leading-relaxed">The most intuitive calendar app ever built. Sync across devices, share events, and never miss a deadline.</p>
                <div class="flex flex-wrap gap-3 mb-6">
                    <button class="bg-black text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 hover:bg-slate-800 transition">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                        App Store
                    </button>
                    <button class="bg-black text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 hover:bg-slate-800 transition">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M3.609 1.814L13.792 12 3.61 22.186a.996.996 0 0 1-.61-.92V2.734a1 1 0 0 1 .609-.92zm10.89 10.893l2.302 2.302-10.937 6.333 8.635-8.635zm3.199-3.198l2.807 1.626a1 1 0 0 1 0 1.73l-2.808 1.626L15.39 12l2.308-2.491zM5.864 2.658l10.937 6.333-2.302 2.301-8.635-8.634z"/></svg>
                        Google Play
                    </button>
                </div>
                <div class="flex items-center gap-2 text-sm text-slate-500">
                    <div class="flex text-yellow-400">★★★★★</div>
                    <span>4.9/5 · 12k reviews</span>
                </div>
            </div>
            <div class="flex justify-center">
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-pink-400 to-violet-500 rounded-[3rem] transform rotate-6 opacity-20 blur-2xl"></div>
                    <img src="https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?auto=format&fit=crop&w=400&q=80" alt="App screenshot" class="relative rounded-[2.5rem] border-8 border-slate-900 shadow-2xl h-[500px] object-cover" loading="lazy">
                </div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Hero Gradient Mesh',
        category: 'Marketing',
        icon: 'layout',
        desc: 'Animated mesh gradient background with bold typography',
        html: `<div class="lumina-layout-block w-full relative overflow-hidden rounded-2xl font-sans" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%); min-height: 400px;">
            <div class="absolute inset-0 opacity-30" style="background-image: radial-gradient(circle at 20% 30%, rgba(255,255,255,0.4) 0%, transparent 50%), radial-gradient(circle at 80% 70%, rgba(255,255,255,0.3) 0%, transparent 50%);"></div>
            <div class="relative z-10 py-24 px-6 text-center text-white">
                <h1 class="text-4xl md:text-6xl font-extrabold mb-6 tracking-tight">Design without limits</h1>
                <p class="text-xl mb-10 max-w-2xl mx-auto opacity-90">Beautiful gradients, smooth animations, pixel-perfect layouts — all from your browser.</p>
                <a href="#" class="inline-flex items-center gap-2 px-8 py-4 bg-white text-purple-700 rounded-full font-bold hover:bg-purple-50 transition shadow-2xl">Start Creating →</a>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // STRUCTURE
    // =====================================================================
    {
        name: '1 Column Text',
        category: 'Structure',
        icon: 'type',
        desc: 'Single-column long-form text',
        html: `<div class="lumina-layout-block w-full max-w-3xl mx-auto py-8 px-6 font-sans">
            <h2 class="text-3xl font-bold text-slate-900 mb-4">Single Column Layout</h2>
            <p class="text-lg text-slate-600 leading-relaxed mb-4">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
            <p class="text-lg text-slate-600 leading-relaxed">Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>
        </div><p><br/></p>`
    },
    {
        name: '2 Columns',
        category: 'Structure',
        icon: 'columns',
        desc: 'Two equal columns',
        html: `<div class="lumina-layout-block w-full max-w-6xl mx-auto py-8 px-6 grid grid-cols-1 md:grid-cols-2 gap-8 font-sans">
            <div><h3 class="text-xl font-bold text-slate-900 mb-3">Left Column</h3><p class="text-slate-600 leading-relaxed">Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore.</p></div>
            <div><h3 class="text-xl font-bold text-slate-900 mb-3">Right Column</h3><p class="text-slate-600 leading-relaxed">Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione.</p></div>
        </div><p><br/></p>`
    },
    {
        name: '3 Columns',
        category: 'Structure',
        icon: 'columns',
        desc: 'Three equal columns',
        html: `<div class="lumina-layout-block w-full max-w-6xl mx-auto py-8 px-6 grid grid-cols-1 md:grid-cols-3 gap-8 font-sans">
            <div class="p-6 bg-slate-50 rounded-xl"><div class="w-10 h-10 bg-blue-100 rounded-lg mb-4 flex items-center justify-center text-blue-600 text-xl">①</div><h3 class="font-bold text-slate-900 mb-2">Step One</h3><p class="text-sm text-slate-600">Plan your strategy and define goals.</p></div>
            <div class="p-6 bg-slate-50 rounded-xl"><div class="w-10 h-10 bg-emerald-100 rounded-lg mb-4 flex items-center justify-center text-emerald-600 text-xl">②</div><h3 class="font-bold text-slate-900 mb-2">Step Two</h3><p class="text-sm text-slate-600">Execute with precision and care.</p></div>
            <div class="p-6 bg-slate-50 rounded-xl"><div class="w-10 h-10 bg-violet-100 rounded-lg mb-4 flex items-center justify-center text-violet-600 text-xl">③</div><h3 class="font-bold text-slate-900 mb-2">Step Three</h3><p class="text-sm text-slate-600">Measure, learn, and iterate.</p></div>
        </div><p><br/></p>`
    },
    {
        name: '4 Columns Stats',
        category: 'Structure',
        icon: 'columns',
        desc: 'Four-column stats row',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-12 px-6 grid grid-cols-2 md:grid-cols-4 gap-8 font-sans text-center">
            <div><div class="text-4xl font-extrabold text-indigo-600 mb-1">12k+</div><div class="text-sm text-slate-500 uppercase tracking-wider">Customers</div></div>
            <div><div class="text-4xl font-extrabold text-emerald-600 mb-1">98%</div><div class="text-sm text-slate-500 uppercase tracking-wider">Satisfaction</div></div>
            <div><div class="text-4xl font-extrabold text-pink-600 mb-1">2.5M</div><div class="text-sm text-slate-500 uppercase tracking-wider">Page Views</div></div>
            <div><div class="text-4xl font-extrabold text-amber-600 mb-1">24/7</div><div class="text-sm text-slate-500 uppercase tracking-wider">Support</div></div>
        </div><p><br/></p>`
    },
    {
        name: 'Sidebar Left',
        category: 'Structure',
        icon: 'sidebar',
        desc: 'Content + left sidebar',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-8 px-6 grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-8 font-sans">
            <aside class="space-y-4">
                <div class="p-4 bg-slate-50 rounded-xl"><h4 class="font-bold text-slate-900 mb-2">Navigation</h4><ul class="space-y-1 text-sm text-slate-600"><li><a href="#" class="hover:text-indigo-600">Overview</a></li><li><a href="#" class="hover:text-indigo-600">Features</a></li><li><a href="#" class="hover:text-indigo-600">Pricing</a></li></ul></div>
                <div class="p-4 bg-indigo-50 rounded-xl"><h4 class="font-bold text-indigo-900 mb-2">Get Started</h4><p class="text-sm text-indigo-700 mb-3">Try our 14-day free trial.</p><button class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded-md">Sign Up</button></div>
            </aside>
            <main><h2 class="text-2xl font-bold text-slate-900 mb-4">Main Content</h2><p class="text-slate-600 leading-relaxed mb-4">This is the main content area with a left sidebar. Perfect for documentation, blogs, or admin layouts.</p><p class="text-slate-600 leading-relaxed">The sidebar can hold navigation, calls-to-action, or related links.</p></main>
        </div><p><br/></p>`
    },
    {
        name: 'Sidebar Right',
        category: 'Structure',
        icon: 'sidebar',
        desc: 'Content + right sidebar',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-8 px-6 grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-8 font-sans">
            <main><h2 class="text-2xl font-bold text-slate-900 mb-4">Main Article</h2><p class="text-slate-600 leading-relaxed mb-4">This is the main content area with a right sidebar. Common in news sites and magazines.</p><p class="text-slate-600 leading-relaxed">The right sidebar typically holds related content, ads, or newsletter signup.</p></main>
            <aside class="space-y-4">
                <div class="p-4 bg-amber-50 rounded-xl border border-amber-100"><h4 class="font-bold text-amber-900 mb-2">💡 Tip of the day</h4><p class="text-sm text-amber-800">Use keyboard shortcuts to speed up your workflow.</p></div>
                <div class="p-4 bg-slate-50 rounded-xl"><h4 class="font-bold text-slate-900 mb-2">Related</h4><ul class="space-y-2 text-sm text-slate-600"><li><a href="#" class="hover:text-indigo-600">→ Article One</a></li><li><a href="#" class="hover:text-indigo-600">→ Article Two</a></li></ul></div>
            </aside>
        </div><p><br/></p>`
    },
    {
        name: 'Sticky Header',
        category: 'Structure',
        icon: 'menu',
        desc: 'Navigation bar (use as page header)',
        html: `<div class="lumina-layout-block w-full bg-white/90 backdrop-blur-md border-b border-slate-200 font-sans sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
                <div class="flex items-center gap-2 font-bold text-xl text-slate-900">
                    <div class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-violet-500 rounded-lg"></div>
                    Brand
                </div>
                <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
                    <a href="#" class="hover:text-slate-900 transition">Home</a>
                    <a href="#" class="hover:text-slate-900 transition">Features</a>
                    <a href="#" class="hover:text-slate-900 transition">Pricing</a>
                    <a href="#" class="hover:text-slate-900 transition">Blog</a>
                    <a href="#" class="px-4 py-2 bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition">Sign In</a>
                </nav>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // CONTENT
    // =====================================================================
    {
        name: 'Features Grid',
        category: 'Content',
        icon: 'grid',
        desc: 'Six-feature grid with icons',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-16 px-6 font-sans">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-slate-900 mb-4">Everything you need</h2>
                <p class="text-lg text-slate-600 max-w-2xl mx-auto">Powerful features designed to make your workflow smoother and your output sharper.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition"><div class="w-12 h-12 bg-indigo-50 rounded-xl flex items-center justify-center mb-4 text-2xl">⚡</div><h3 class="font-bold text-slate-900 mb-2">Lightning Fast</h3><p class="text-sm text-slate-600">Optimized for speed — pages load in under 100ms.</p></div>
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition"><div class="w-12 h-12 bg-emerald-50 rounded-xl flex items-center justify-center mb-4 text-2xl">🔒</div><h3 class="font-bold text-slate-900 mb-2">Secure by Default</h3><p class="text-sm text-slate-600">End-to-end encryption keeps your data private.</p></div>
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition"><div class="w-12 h-12 bg-pink-50 rounded-xl flex items-center justify-center mb-4 text-2xl">🎨</div><h3 class="font-bold text-slate-900 mb-2">Beautiful Design</h3><p class="text-sm text-slate-600">Pre-built themes that look stunning out of the box.</p></div>
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition"><div class="w-12 h-12 bg-amber-50 rounded-xl flex items-center justify-center mb-4 text-2xl">📊</div><h3 class="font-bold text-slate-900 mb-2">Analytics Built-in</h3><p class="text-sm text-slate-600">Track every metric without external tools.</p></div>
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition"><div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center mb-4 text-2xl">🌐</div><h3 class="font-bold text-slate-900 mb-2">Multi-language</h3><p class="text-sm text-slate-600">Reach global audiences with i18n support.</p></div>
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md transition"><div class="w-12 h-12 bg-violet-50 rounded-xl flex items-center justify-center mb-4 text-2xl">🚀</div><h3 class="font-bold text-slate-900 mb-2">Auto-scaling</h3><p class="text-sm text-slate-600">From 1 to 1M users without a hiccup.</p></div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Feature Zigzag',
        category: 'Content',
        icon: 'layout',
        desc: 'Alternating image/text feature rows',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-16 px-6 space-y-20 font-sans">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
                <div><span class="text-xs font-bold uppercase tracking-wider text-indigo-600">Feature 01</span><h3 class="text-2xl font-bold text-slate-900 mt-2 mb-4">Real-time collaboration</h3><p class="text-slate-600 leading-relaxed mb-4">Work together with your team in real time. See changes as they happen, leave comments, and ship faster.</p><ul class="space-y-2 text-sm text-slate-700"><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Live cursors and presence</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Version history</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Inline comments</li></ul></div>
                <img src="https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=800&q=80" alt="Collaboration" class="rounded-2xl shadow-xl w-full aspect-[4/3] object-cover" loading="lazy">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
                <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=800&q=80" alt="Analytics" class="rounded-2xl shadow-xl w-full aspect-[4/3] object-cover md:order-1 order-2" loading="lazy">
                <div class="md:order-2 order-1"><span class="text-xs font-bold uppercase tracking-wider text-emerald-600">Feature 02</span><h3 class="text-2xl font-bold text-slate-900 mt-2 mb-4">Powerful analytics</h3><p class="text-slate-600 leading-relaxed mb-4">Understand your users with beautiful, real-time dashboards. Make data-driven decisions without leaving the app.</p><ul class="space-y-2 text-sm text-slate-700"><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Custom dashboards</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Real-time events</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Export to CSV</li></ul></div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Article Header',
        category: 'Content',
        icon: 'type',
        desc: 'Blog post header with metadata',
        html: `<div class="lumina-layout-block w-full max-w-3xl mx-auto py-12 px-6 font-sans">
            <div class="flex items-center gap-2 text-sm text-slate-500 mb-4">
                <span class="px-2 py-1 bg-indigo-50 text-indigo-700 rounded text-xs font-semibold uppercase">Technology</span>
                <span>·</span><span>5 min read</span><span>·</span><span>March 15, 2026</span>
            </div>
            <h1 class="text-4xl md:text-5xl font-bold text-slate-900 mb-6 leading-tight">The future of web design is here, and it's block-based.</h1>
            <p class="text-xl text-slate-600 mb-8 leading-relaxed">An in-depth look at how modular design systems are reshaping the way we build websites.</p>
            <div class="flex items-center gap-4 pt-6 border-t border-slate-100">
                <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=80&q=80" alt="Author" class="w-12 h-12 rounded-full">
                <div><div class="font-semibold text-slate-900">Anna Petrova</div><div class="text-sm text-slate-500">Senior Editor</div></div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Quote Large',
        category: 'Content',
        icon: 'quote',
        desc: 'Featured pull-quote',
        html: `<div class="lumina-layout-block w-full max-w-4xl mx-auto py-16 px-6 font-sans text-center">
            <div class="text-6xl text-indigo-200 leading-none mb-4">"</div>
            <blockquote class="text-2xl md:text-3xl font-medium text-slate-800 leading-relaxed mb-8">Design is not just what it looks like and feels like. Design is how it works.</blockquote>
            <div class="flex items-center justify-center gap-3">
                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-slate-700 to-slate-900 flex items-center justify-center text-white font-bold">S</div>
                <div class="text-left"><div class="font-bold text-slate-900">Steve Jobs</div><div class="text-sm text-slate-500">Apple Co-founder</div></div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // MEDIA
    // =====================================================================
    {
        name: 'Image Gallery',
        category: 'Media',
        icon: 'image',
        desc: '3x2 responsive photo grid',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-12 px-6 font-sans">
            <h2 class="text-2xl font-bold text-slate-900 mb-6">Gallery</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <img src="https://images.unsplash.com/photo-1506905925346-21bda4d32df4?auto=format&fit=crop&w=600&q=80" alt="Mountain" class="rounded-xl w-full aspect-square object-cover hover:opacity-90 transition cursor-pointer" loading="lazy">
                <img src="https://images.unsplash.com/photo-1501785888041-af3ef285b470?auto=format&fit=crop&w=600&q=80" alt="Lake" class="rounded-xl w-full aspect-square object-cover hover:opacity-90 transition cursor-pointer" loading="lazy">
                <img src="https://images.unsplash.com/photo-1470770841072-f978cf4d019e?auto=format&fit=crop&w=600&q=80" alt="Forest" class="rounded-xl w-full aspect-square object-cover hover:opacity-90 transition cursor-pointer" loading="lazy">
                <img src="https://images.unsplash.com/photo-1518837695005-2083093ee35b?auto=format&fit=crop&w=600&q=80" alt="Ocean" class="rounded-xl w-full aspect-square object-cover hover:opacity-90 transition cursor-pointer" loading="lazy">
                <img src="https://images.unsplash.com/photo-1469474968028-56623f02e42e?auto=format&fit=crop&w=600&q=80" alt="Sunset" class="rounded-xl w-full aspect-square object-cover hover:opacity-90 transition cursor-pointer" loading="lazy">
                <img src="https://images.unsplash.com/photo-1426604966848-d7adac402bff?auto=format&fit=crop&w=600&q=80" alt="Valley" class="rounded-xl w-full aspect-square object-cover hover:opacity-90 transition cursor-pointer" loading="lazy">
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Video Player',
        category: 'Media',
        icon: 'video',
        desc: 'Responsive YouTube embed with overlay',
        html: `<div class="lumina-layout-block w-full max-w-5xl mx-auto py-8 px-6 font-sans">
            <div class="relative rounded-2xl overflow-hidden shadow-2xl bg-black aspect-video">
                <iframe class="absolute inset-0 w-full h-full" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="Video Player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
            </div>
            <div class="mt-4 flex items-center justify-between">
                <div><h3 class="font-bold text-slate-900">Product Demo</h3><p class="text-sm text-slate-500">3:24 · 12k views</p></div>
                <div class="flex gap-2">
                    <button class="px-3 py-1.5 text-sm bg-slate-100 rounded-lg hover:bg-slate-200 transition">Share</button>
                    <button class="px-3 py-1.5 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">Subscribe</button>
                </div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Map Location',
        category: 'Media',
        icon: 'map',
        desc: 'Google Maps embed with address',
        html: `<div class="lumina-layout-block w-full max-w-5xl mx-auto py-8 px-6 font-sans">
            <div class="grid grid-cols-1 md:grid-cols-[1fr_300px] gap-6">
                <div class="rounded-2xl overflow-hidden shadow-xl aspect-video md:aspect-auto md:min-h-[400px]">
                    <iframe class="w-full h-full" src="https://maps.google.com/maps?q=New%20York&t=&z=13&ie=UTF8&iwloc=&output=embed" style="border:0;" loading="lazy"></iframe>
                </div>
                <div class="space-y-4">
                    <div><h3 class="font-bold text-slate-900 mb-1">Our Office</h3><p class="text-sm text-slate-600">123 Main Street, Suite 400<br>New York, NY 10001</p></div>
                    <div class="pt-4 border-t border-slate-100"><h4 class="font-semibold text-slate-900 mb-1">Hours</h4><p class="text-sm text-slate-600">Mon–Fri: 9am–6pm<br>Sat: 10am–4pm<br>Sun: Closed</p></div>
                    <a href="#" class="inline-block mt-4 px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 transition">Get Directions →</a>
                </div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // COMMERCE
    // =====================================================================
    {
        name: 'Pricing 3-Tier',
        category: 'Commerce',
        icon: 'tag',
        desc: 'Three pricing cards with highlighted middle tier',
        html: `<div class="lumina-layout-block w-full max-w-6xl mx-auto py-16 px-6 font-sans">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-slate-900 mb-3">Simple, transparent pricing</h2>
                <p class="text-slate-600">Choose the plan that fits your team. No hidden fees.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="p-8 bg-white rounded-2xl border border-slate-200"><div class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-2">Starter</div><div class="mb-6"><span class="text-4xl font-bold text-slate-900">$0</span><span class="text-slate-500">/mo</span></div><ul class="space-y-3 mb-8 text-sm text-slate-600"><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Up to 3 projects</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> 1GB storage</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Community support</li></ul><button class="w-full py-3 border border-slate-300 text-slate-700 rounded-lg font-semibold hover:bg-slate-50 transition">Get Started</button></div>
                <div class="p-8 bg-gradient-to-br from-indigo-600 to-violet-600 rounded-2xl text-white relative shadow-2xl scale-105"><div class="absolute top-4 right-4 px-2 py-1 bg-white/20 backdrop-blur rounded text-xs font-bold uppercase">Popular</div><div class="text-sm font-bold uppercase tracking-wider mb-2 opacity-80">Pro</div><div class="mb-6"><span class="text-4xl font-bold">$29</span><span class="opacity-80">/mo</span></div><ul class="space-y-3 mb-8 text-sm"><li class="flex items-center gap-2"><span>✓</span> Unlimited projects</li><li class="flex items-center gap-2"><span>✓</span> 100GB storage</li><li class="flex items-center gap-2"><span>✓</span> Priority support</li><li class="flex items-center gap-2"><span>✓</span> Advanced analytics</li></ul><button class="w-full py-3 bg-white text-indigo-700 rounded-lg font-bold hover:bg-indigo-50 transition">Start Free Trial</button></div>
                <div class="p-8 bg-white rounded-2xl border border-slate-200"><div class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-2">Enterprise</div><div class="mb-6"><span class="text-4xl font-bold text-slate-900">Custom</span></div><ul class="space-y-3 mb-8 text-sm text-slate-600"><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Everything in Pro</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Unlimited storage</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Dedicated manager</li><li class="flex items-center gap-2"><span class="text-emerald-500">✓</span> Custom SLAs</li></ul><button class="w-full py-3 border border-slate-300 text-slate-700 rounded-lg font-semibold hover:bg-slate-50 transition">Contact Sales</button></div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Product Grid',
        category: 'Commerce',
        icon: 'shopping-bag',
        desc: 'Four-product shop grid',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-12 px-6 font-sans">
            <div class="flex items-center justify-between mb-8">
                <h2 class="text-2xl font-bold text-slate-900">Featured Products</h2>
                <a href="#" class="text-sm text-indigo-600 hover:text-indigo-700 font-semibold">View all →</a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <div class="group"><div class="aspect-square bg-slate-100 rounded-xl overflow-hidden mb-3"><img src="https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=400&q=80" alt="Sneakers" class="w-full h-full object-cover group-hover:scale-105 transition" loading="lazy"></div><h3 class="font-semibold text-slate-900 text-sm">Air Runner X</h3><div class="text-slate-500 text-sm mb-2">Running Shoes</div><div class="font-bold text-slate-900">$129</div></div>
                <div class="group"><div class="aspect-square bg-slate-100 rounded-xl overflow-hidden mb-3"><img src="https://images.unsplash.com/photo-1542219550-37153d387c27?auto=format&fit=crop&w=400&q=80" alt="Backpack" class="w-full h-full object-cover group-hover:scale-105 transition" loading="lazy"></div><h3 class="font-semibold text-slate-900 text-sm">Urban Pack</h3><div class="text-slate-500 text-sm mb-2">Backpack</div><div class="font-bold text-slate-900">$89</div></div>
                <div class="group"><div class="aspect-square bg-slate-100 rounded-xl overflow-hidden mb-3"><img src="https://images.unsplash.com/photo-1572635196237-14b3f281503f?auto=format&fit=crop&w=400&q=80" alt="Sunglasses" class="w-full h-full object-cover group-hover:scale-105 transition" loading="lazy"></div><h3 class="font-semibold text-slate-900 text-sm">Aviator Pro</h3><div class="text-slate-500 text-sm mb-2">Sunglasses</div><div class="font-bold text-slate-900">$59</div></div>
                <div class="group"><div class="aspect-square bg-slate-100 rounded-xl overflow-hidden mb-3"><img src="https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=400&q=80" alt="Watch" class="w-full h-full object-cover group-hover:scale-105 transition" loading="lazy"></div><h3 class="font-semibold text-slate-900 text-sm">Chrono Sport</h3><div class="text-slate-500 text-sm mb-2">Watch</div><div class="font-bold text-slate-900">$249</div></div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Promo Banner',
        category: 'Commerce',
        icon: 'tag',
        desc: 'Promotional banner with gradient background',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto my-8 px-6 font-sans">
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 p-8 md:p-12">
                <div class="absolute top-0 right-0 w-64 h-64 bg-white/10 rounded-full -translate-y-32 translate-x-32"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 bg-white/10 rounded-full translate-y-24 -translate-x-24"></div>
                <div class="relative z-10 flex flex-col md:flex-row items-center justify-between gap-6 text-white">
                    <div><span class="inline-block px-3 py-1 bg-white/20 backdrop-blur rounded-full text-xs font-bold uppercase mb-3">Limited Time</span><h3 class="text-2xl md:text-3xl font-bold mb-2">50% Off All Annual Plans</h3><p class="opacity-90">Offer ends December 31st. Don't miss out.</p></div>
                    <a href="#" class="px-6 py-3 bg-white text-indigo-700 rounded-full font-bold hover:bg-indigo-50 transition shadow-xl whitespace-nowrap">Claim Offer →</a>
                </div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // DATA
    // =====================================================================
    {
        name: 'Stats Row',
        category: 'Data',
        icon: 'bar-chart',
        desc: 'Big-number stats with labels',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-16 px-6 font-sans">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
                <div class="p-6"><div class="text-5xl font-extrabold bg-gradient-to-br from-indigo-500 to-violet-500 bg-clip-text text-transparent mb-2">2.5M+</div><div class="text-sm text-slate-500 uppercase tracking-wider font-semibold">Active Users</div></div>
                <div class="p-6"><div class="text-5xl font-extrabold bg-gradient-to-br from-emerald-500 to-teal-500 bg-clip-text text-transparent mb-2">99.9%</div><div class="text-sm text-slate-500 uppercase tracking-wider font-semibold">Uptime</div></div>
                <div class="p-6"><div class="text-5xl font-extrabold bg-gradient-to-br from-pink-500 to-rose-500 bg-clip-text text-transparent mb-2">150+</div><div class="text-sm text-slate-500 uppercase tracking-wider font-semibold">Countries</div></div>
                <div class="p-6"><div class="text-5xl font-extrabold bg-gradient-to-br from-amber-500 to-orange-500 bg-clip-text text-transparent mb-2">24/7</div><div class="text-sm text-slate-500 uppercase tracking-wider font-semibold">Support</div></div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Timeline Vertical',
        category: 'Data',
        icon: 'list',
        desc: 'Vertical timeline with milestones',
        html: `<div class="lumina-layout-block w-full max-w-3xl mx-auto py-12 px-6 font-sans">
            <h2 class="text-2xl font-bold text-slate-900 mb-10 text-center">Our Journey</h2>
            <div class="relative pl-8">
                <div class="absolute left-3 top-2 bottom-2 w-0.5 bg-slate-200"></div>
                <div class="relative mb-10"><div class="absolute -left-7 top-1 w-6 h-6 rounded-full bg-indigo-600 border-4 border-white shadow"></div><div class="text-xs text-indigo-600 font-bold uppercase mb-1">2020</div><h3 class="font-bold text-slate-900 mb-1">Founded</h3><p class="text-slate-600 text-sm">Started in a garage with three founders and a big idea.</p></div>
                <div class="relative mb-10"><div class="absolute -left-7 top-1 w-6 h-6 rounded-full bg-emerald-600 border-4 border-white shadow"></div><div class="text-xs text-emerald-600 font-bold uppercase mb-1">2022</div><h3 class="font-bold text-slate-900 mb-1">Series A</h3><p class="text-slate-600 text-sm">Raised $10M to scale the platform and grow the team.</p></div>
                <div class="relative mb-10"><div class="absolute -left-7 top-1 w-6 h-6 rounded-full bg-pink-600 border-4 border-white shadow"></div><div class="text-xs text-pink-600 font-bold uppercase mb-1">2024</div><h3 class="font-bold text-slate-900 mb-1">1M Users</h3><p class="text-slate-600 text-sm">Crossed one million active users worldwide.</p></div>
                <div class="relative"><div class="absolute -left-7 top-1 w-6 h-6 rounded-full bg-amber-500 border-4 border-white shadow"></div><div class="text-xs text-amber-600 font-bold uppercase mb-1">2026</div><h3 class="font-bold text-slate-900 mb-1">Today</h3><p class="text-slate-600 text-sm">Serving 2.5M+ users across 150 countries.</p></div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Data Table',
        category: 'Data',
        icon: 'table',
        desc: 'Styled data table with hover rows',
        html: `<div class="lumina-layout-block w-full max-w-5xl mx-auto py-8 px-6 font-sans overflow-x-auto">
            <h2 class="text-xl font-bold text-slate-900 mb-4">Recent Orders</h2>
            <table class="w-full text-sm"><thead><tr class="text-left text-slate-500 border-b border-slate-200"><th class="py-3 px-2 font-semibold">Order ID</th><th class="py-3 px-2 font-semibold">Customer</th><th class="py-3 px-2 font-semibold">Date</th><th class="py-3 px-2 font-semibold">Status</th><th class="py-3 px-2 font-semibold text-right">Amount</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr class="hover:bg-slate-50 transition"><td class="py-3 px-2 font-mono text-slate-600">#A-1024</td><td class="py-3 px-2 font-medium text-slate-900">Anna Petrova</td><td class="py-3 px-2 text-slate-500">Mar 15, 2026</td><td class="py-3 px-2"><span class="px-2 py-1 bg-emerald-50 text-emerald-700 rounded text-xs font-semibold">Paid</span></td><td class="py-3 px-2 text-right font-semibold">$1,299</td></tr>
                    <tr class="hover:bg-slate-50 transition"><td class="py-3 px-2 font-mono text-slate-600">#A-1025</td><td class="py-3 px-2 font-medium text-slate-900">John Smith</td><td class="py-3 px-2 text-slate-500">Mar 15, 2026</td><td class="py-3 px-2"><span class="px-2 py-1 bg-amber-50 text-amber-700 rounded text-xs font-semibold">Pending</span></td><td class="py-3 px-2 text-right font-semibold">$89</td></tr>
                    <tr class="hover:bg-slate-50 transition"><td class="py-3 px-2 font-mono text-slate-600">#A-1026</td><td class="py-3 px-2 font-medium text-slate-900">Yuki Tanaka</td><td class="py-3 px-2 text-slate-500">Mar 14, 2026</td><td class="py-3 px-2"><span class="px-2 py-1 bg-emerald-50 text-emerald-700 rounded text-xs font-semibold">Paid</span></td><td class="py-3 px-2 text-right font-semibold">$459</td></tr>
                    <tr class="hover:bg-slate-50 transition"><td class="py-3 px-2 font-mono text-slate-600">#A-1027</td><td class="py-3 px-2 font-medium text-slate-900">Maria Garcia</td><td class="py-3 px-2 text-slate-500">Mar 14, 2026</td><td class="py-3 px-2"><span class="px-2 py-1 bg-rose-50 text-rose-700 rounded text-xs font-semibold">Refunded</span></td><td class="py-3 px-2 text-right font-semibold">$0</td></tr>
                </tbody>
            </table>
        </div><p><br/></p>`
    },

    // =====================================================================
    // SOCIAL PROOF / MARKETING
    // =====================================================================
    {
        name: 'Testimonials Grid',
        category: 'Marketing',
        icon: 'message-square',
        desc: 'Three customer testimonials',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-16 px-6 font-sans">
            <div class="text-center mb-12"><h2 class="text-3xl font-bold text-slate-900 mb-3">Loved by creators worldwide</h2><p class="text-slate-600">Don't just take our word for it.</p></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm"><div class="flex text-yellow-400 mb-3">★★★★★</div><p class="text-slate-700 mb-6 leading-relaxed">"This is the best editor I've ever used. It saved me hours every week and my clients love the results."</p><div class="flex items-center gap-3"><img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=80&q=80" alt="" class="w-10 h-10 rounded-full"><div><div class="font-semibold text-slate-900 text-sm">Sarah Chen</div><div class="text-xs text-slate-500">Designer at Studio Inc.</div></div></div></div>
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm"><div class="flex text-yellow-400 mb-3">★★★★★</div><p class="text-slate-700 mb-6 leading-relaxed">"We replaced three different tools with this one platform. The ROI was immediate and the team adoption was effortless."</p><div class="flex items-center gap-3"><img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=80&q=80" alt="" class="w-10 h-10 rounded-full"><div><div class="font-semibold text-slate-900 text-sm">Marcus Reed</div><div class="text-xs text-slate-500">CTO at TechCorp</div></div></div></div>
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm"><div class="flex text-yellow-400 mb-3">★★★★★</div><p class="text-slate-700 mb-6 leading-relaxed">"Beautiful templates, smooth animations, and the easiest editor I've used. Highly recommend for any agency."</p><div class="flex items-center gap-3"><img src="https://images.unsplash.com/photo-1438761681033-6461ffad8d80?auto=format&fit=crop&w=80&q=80" alt="" class="w-10 h-10 rounded-full"><div><div class="font-semibold text-slate-900 text-sm">Lena Park</div><div class="text-xs text-slate-500">Founder, ParkStudio</div></div></div></div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Logo Cloud',
        category: 'Marketing',
        icon: 'grid',
        desc: 'Trusted-by logo grid',
        html: `<div class="lumina-layout-block w-full max-w-5xl mx-auto py-12 px-6 font-sans text-center">
            <p class="text-sm font-semibold uppercase tracking-wider text-slate-400 mb-8">Trusted by teams at</p>
            <div class="grid grid-cols-2 md:grid-cols-6 gap-8 items-center">
                <div class="text-2xl font-bold text-slate-300 hover:text-slate-500 transition">Acme</div>
                <div class="text-2xl font-bold text-slate-300 hover:text-slate-500 transition italic">Globex</div>
                <div class="text-2xl font-bold text-slate-300 hover:text-slate-500 transition">Stark</div>
                <div class="text-2xl font-bold text-slate-300 hover:text-slate-500 transition">Wayne</div>
                <div class="text-2xl font-bold text-slate-300 hover:text-slate-500 transition italic">Umbrella</div>
                <div class="text-2xl font-bold text-slate-300 hover:text-slate-500 transition">Cyberdyne</div>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'CTA Banner',
        category: 'Marketing',
        icon: 'megaphone',
        desc: 'Call-to-action banner with gradient',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto my-12 px-6 font-sans">
            <div class="bg-slate-900 rounded-3xl px-8 py-16 md:py-20 text-center relative overflow-hidden">
                <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-gradient-to-br from-indigo-500/20 to-violet-500/20 rounded-full blur-3xl"></div>
                <div class="relative z-10 max-w-2xl mx-auto">
                    <h2 class="text-3xl md:text-5xl font-bold text-white mb-4">Ready to ship faster?</h2>
                    <p class="text-lg text-slate-300 mb-8">Join thousands of teams building beautiful products with Lumina.</p>
                    <div class="flex flex-col sm:flex-row gap-4 justify-center">
                        <a href="#" class="px-8 py-4 bg-white text-slate-900 rounded-full font-bold hover:bg-slate-100 transition shadow-2xl">Start Free Trial</a>
                        <a href="#" class="px-8 py-4 border border-slate-600 text-white rounded-full font-bold hover:bg-slate-800 transition">Talk to Sales</a>
                    </div>
                </div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // STRUCTURE / FORMS
    // =====================================================================
    {
        name: 'Contact Form',
        category: 'Structure',
        icon: 'mail',
        desc: 'Contact form with sidebar info',
        html: `<div class="lumina-layout-block w-full max-w-5xl mx-auto py-12 px-6 font-sans">
            <div class="grid grid-cols-1 md:grid-cols-[1fr_300px] gap-12">
                <div><h2 class="text-3xl font-bold text-slate-900 mb-2">Get in touch</h2><p class="text-slate-600 mb-8">We'd love to hear from you. Send us a message and we'll respond within 24 hours.</p>
                    <form class="space-y-4"><div><label class="block text-sm font-medium text-slate-700 mb-1">Name</label><input type="text" placeholder="Your name"></div><div><label class="block text-sm font-medium text-slate-700 mb-1">Email</label><input type="email" placeholder="you@example.com"></div><div><label class="block text-sm font-medium text-slate-700 mb-1">Message</label><textarea rows="5" placeholder="How can we help?"></textarea></div><button type="button" class="px-6 py-3 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition">Send Message</button></form>
                </div>
                <aside class="space-y-6">
                    <div><h4 class="font-bold text-slate-900 mb-2">📧 Email</h4><p class="text-sm text-slate-600">hello@example.com</p></div>
                    <div><h4 class="font-bold text-slate-900 mb-2">📞 Phone</h4><p class="text-sm text-slate-600">+1 (555) 123-4567</p></div>
                    <div><h4 class="font-bold text-slate-900 mb-2">📍 Address</h4><p class="text-sm text-slate-600">123 Main St<br>New York, NY 10001</p></div>
                </aside>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Newsletter Signup',
        category: 'Marketing',
        icon: 'mail',
        desc: 'Inline newsletter signup',
        html: `<div class="lumina-layout-block w-full max-w-3xl mx-auto py-12 px-6 font-sans text-center">
            <div class="text-4xl mb-4">📬</div>
            <h2 class="text-2xl font-bold text-slate-900 mb-2">Subscribe to our newsletter</h2>
            <p class="text-slate-600 mb-6">Get the latest updates, tips, and tutorials delivered to your inbox. No spam, ever.</p>
            <form class="flex flex-col sm:flex-row gap-3 max-w-md mx-auto" onsubmit="return false"><input type="email" placeholder="you@example.com" class="flex-1"><button type="button" class="px-6 py-3 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition whitespace-nowrap">Subscribe</button></form>
            <p class="text-xs text-slate-400 mt-4">By subscribing, you agree to our Privacy Policy.</p>
        </div><p><br/></p>`
    },
    {
        name: 'FAQ Accordion',
        category: 'Content',
        icon: 'help-circle',
        desc: 'Frequently asked questions',
        html: `<div class="lumina-layout-block w-full max-w-3xl mx-auto py-12 px-6 font-sans">
            <h2 class="text-3xl font-bold text-slate-900 mb-8 text-center">Frequently Asked Questions</h2>
            <div class="space-y-3">
                <details class="group p-5 bg-white rounded-xl border border-slate-200"><summary class="font-semibold text-slate-900 cursor-pointer flex items-center justify-between">Is there a free trial?<span class="text-slate-400 group-open:rotate-180 transition">▼</span></summary><p class="mt-3 text-slate-600 text-sm leading-relaxed">Yes! All paid plans come with a 14-day free trial. No credit card required to get started.</p></details>
                <details class="group p-5 bg-white rounded-xl border border-slate-200"><summary class="font-semibold text-slate-900 cursor-pointer flex items-center justify-between">Can I cancel anytime?<span class="text-slate-400 group-open:rotate-180 transition">▼</span></summary><p class="mt-3 text-slate-600 text-sm leading-relaxed">Absolutely. Cancel with one click from your dashboard — no questions asked, no hidden fees.</p></details>
                <details class="group p-5 bg-white rounded-xl border border-slate-200"><summary class="font-semibold text-slate-900 cursor-pointer flex items-center justify-between">Do you offer refunds?<span class="text-slate-400 group-open:rotate-180 transition">▼</span></summary><p class="mt-3 text-slate-600 text-sm leading-relaxed">We offer a 30-day money-back guarantee on all annual plans. Just contact our support team.</p></details>
                <details class="group p-5 bg-white rounded-xl border border-slate-200"><summary class="font-semibold text-slate-900 cursor-pointer flex items-center justify-between">Is my data secure?<span class="text-slate-400 group-open:rotate-180 transition">▼</span></summary><p class="mt-3 text-slate-600 text-sm leading-relaxed">All data is encrypted in transit and at rest. We're SOC 2 Type II certified and GDPR compliant.</p></details>
            </div>
        </div><p><br/></p>`
    },
    {
        name: 'Login Form',
        category: 'Structure',
        icon: 'lock',
        desc: 'Centered login card',
        html: `<div class="lumina-layout-block w-full max-w-md mx-auto py-12 px-6 font-sans">
            <div class="bg-white p-8 rounded-2xl shadow-xl border border-slate-100">
                <div class="text-center mb-6"><div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-violet-500 rounded-xl mx-auto mb-3"></div><h2 class="text-2xl font-bold text-slate-900">Welcome back</h2><p class="text-sm text-slate-500 mt-1">Sign in to your account</p></div>
                <form class="space-y-4"><div><label class="block text-sm font-medium text-slate-700 mb-1">Email</label><input type="email" placeholder="you@example.com"></div><div><label class="block text-sm font-medium text-slate-700 mb-1">Password</label><input type="password" placeholder="••••••••"></div><div class="flex items-center justify-between text-sm"><label class="flex items-center gap-2 text-slate-600"><input type="checkbox"> Remember me</label><a href="#" class="text-indigo-600 hover:text-indigo-700">Forgot password?</a></div><button type="button" class="w-full py-3 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition">Sign In</button></form>
                <p class="text-center text-sm text-slate-500 mt-6">Don't have an account? <a href="#" class="text-indigo-600 hover:text-indigo-700 font-semibold">Sign up</a></p>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // TEAM
    // =====================================================================
    {
        name: 'Team Grid',
        category: 'Marketing',
        icon: 'users',
        desc: 'Four team members with photos',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-16 px-6 font-sans">
            <div class="text-center mb-12"><h2 class="text-3xl font-bold text-slate-900 mb-3">Meet the team</h2><p class="text-slate-600">The brilliant minds behind the product.</p></div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <div class="text-center"><div class="aspect-square rounded-2xl overflow-hidden mb-3 bg-slate-100"><img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=400&q=80" alt="" class="w-full h-full object-cover" loading="lazy"></div><h3 class="font-bold text-slate-900">Anna Petrova</h3><p class="text-sm text-slate-500 mb-2">CEO & Co-founder</p><div class="flex justify-center gap-2 text-slate-400"><a href="#" class="hover:text-indigo-600">in</a><a href="#" class="hover:text-indigo-600">𝕏</a></div></div>
                <div class="text-center"><div class="aspect-square rounded-2xl overflow-hidden mb-3 bg-slate-100"><img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=400&q=80" alt="" class="w-full h-full object-cover" loading="lazy"></div><h3 class="font-bold text-slate-900">Marcus Reed</h3><p class="text-sm text-slate-500 mb-2">CTO & Co-founder</p><div class="flex justify-center gap-2 text-slate-400"><a href="#" class="hover:text-indigo-600">in</a><a href="#" class="hover:text-indigo-600">𝕏</a></div></div>
                <div class="text-center"><div class="aspect-square rounded-2xl overflow-hidden mb-3 bg-slate-100"><img src="https://images.unsplash.com/photo-1438761681033-6461ffad8d80?auto=format&fit=crop&w=400&q=80" alt="" class="w-full h-full object-cover" loading="lazy"></div><h3 class="font-bold text-slate-900">Lena Park</h3><p class="text-sm text-slate-500 mb-2">Head of Design</p><div class="flex justify-center gap-2 text-slate-400"><a href="#" class="hover:text-indigo-600">in</a><a href="#" class="hover:text-indigo-600">𝕏</a></div></div>
                <div class="text-center"><div class="aspect-square rounded-2xl overflow-hidden mb-3 bg-slate-100"><img src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=400&q=80" alt="" class="w-full h-full object-cover" loading="lazy"></div><h3 class="font-bold text-slate-900">James Lee</h3><p class="text-sm text-slate-500 mb-2">Lead Engineer</p><div class="flex justify-center gap-2 text-slate-400"><a href="#" class="hover:text-indigo-600">in</a><a href="#" class="hover:text-indigo-600">𝕏</a></div></div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // FOOTER
    // =====================================================================
    {
        name: 'Footer Simple',
        category: 'Structure',
        icon: 'footer',
        desc: 'Clean footer with links',
        html: `<div class="lumina-layout-block w-full bg-slate-50 border-t border-slate-200 mt-12 font-sans">
            <div class="max-w-7xl mx-auto px-6 py-12 grid grid-cols-2 md:grid-cols-4 gap-8">
                <div class="col-span-2 md:col-span-1"><div class="flex items-center gap-2 font-bold text-xl text-slate-900 mb-3"><div class="w-8 h-8 bg-gradient-to-br from-indigo-500 to-violet-500 rounded-lg"></div>Brand</div><p class="text-sm text-slate-600">Building the future of web design, one block at a time.</p></div>
                <div><h4 class="font-semibold text-slate-900 mb-3 text-sm">Product</h4><ul class="space-y-2 text-sm text-slate-600"><li><a href="#" class="hover:text-indigo-600">Features</a></li><li><a href="#" class="hover:text-indigo-600">Pricing</a></li><li><a href="#" class="hover:text-indigo-600">Changelog</a></li></ul></div>
                <div><h4 class="font-semibold text-slate-900 mb-3 text-sm">Company</h4><ul class="space-y-2 text-sm text-slate-600"><li><a href="#" class="hover:text-indigo-600">About</a></li><li><a href="#" class="hover:text-indigo-600">Blog</a></li><li><a href="#" class="hover:text-indigo-600">Careers</a></li></ul></div>
                <div><h4 class="font-semibold text-slate-900 mb-3 text-sm">Legal</h4><ul class="space-y-2 text-sm text-slate-600"><li><a href="#" class="hover:text-indigo-600">Privacy</a></li><li><a href="#" class="hover:text-indigo-600">Terms</a></li><li><a href="#" class="hover:text-indigo-600">Security</a></li></ul></div>
            </div>
            <div class="border-t border-slate-200 py-6 text-center text-sm text-slate-500">© 2026 Brand Inc. All rights reserved.</div>
        </div><p><br/></p>`
    },
    {
        name: 'Footer Dark',
        category: 'Structure',
        icon: 'footer',
        desc: 'Dark footer with newsletter',
        html: `<div class="lumina-layout-block w-full bg-slate-900 text-slate-300 mt-12 font-sans">
            <div class="max-w-7xl mx-auto px-6 py-16">
                <div class="grid grid-cols-1 md:grid-cols-[2fr_1fr_1fr_1fr] gap-8 mb-12">
                    <div><div class="flex items-center gap-2 font-bold text-2xl text-white mb-4"><div class="w-9 h-9 bg-gradient-to-br from-indigo-500 to-violet-500 rounded-lg"></div>Brand</div><p class="text-sm text-slate-400 mb-6 max-w-xs">The modern platform for building beautiful, fast websites without code.</p>
                        <form class="flex gap-2 max-w-xs" onsubmit="return false"><input type="email" placeholder="Your email" class="bg-slate-800 border-slate-700 text-white placeholder-slate-500"><button type="button" class="px-4 py-3 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition">→</button></form>
                    </div>
                    <div><h4 class="font-semibold text-white mb-3 text-sm">Product</h4><ul class="space-y-2 text-sm"><li><a href="#" class="hover:text-white">Features</a></li><li><a href="#" class="hover:text-white">Pricing</a></li><li><a href="#" class="hover:text-white">Templates</a></li><li><a href="#" class="hover:text-white">Integrations</a></li></ul></div>
                    <div><h4 class="font-semibold text-white mb-3 text-sm">Resources</h4><ul class="space-y-2 text-sm"><li><a href="#" class="hover:text-white">Documentation</a></li><li><a href="#" class="hover:text-white">Tutorials</a></li><li><a href="#" class="hover:text-white">Blog</a></li><li><a href="#" class="hover:text-white">Support</a></li></ul></div>
                    <div><h4 class="font-semibold text-white mb-3 text-sm">Company</h4><ul class="space-y-2 text-sm"><li><a href="#" class="hover:text-white">About</a></li><li><a href="#" class="hover:text-white">Careers</a></li><li><a href="#" class="hover:text-white">Press</a></li><li><a href="#" class="hover:text-white">Contact</a></li></ul></div>
                </div>
                <div class="pt-8 border-t border-slate-800 flex flex-col md:flex-row justify-between items-center gap-4 text-sm text-slate-500">
                    <p>© 2026 Brand Inc. All rights reserved.</p>
                    <div class="flex gap-6"><a href="#" class="hover:text-white">Privacy</a><a href="#" class="hover:text-white">Terms</a><a href="#" class="hover:text-white">Cookies</a></div>
                </div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: BENTO GRID (Modern Tailwind 2024-2026 trend)
    // =====================================================================
    {
        name: 'Bento Grid',
        category: 'Content',
        icon: 'grid',
        desc: 'Modern bento-style asymmetric grid',
        html: `<div class="lumina-layout-block w-full max-w-7xl mx-auto py-12 px-6 font-sans">
            <h2 class="text-3xl font-bold text-slate-900 mb-8">Why teams choose us</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 auto-rows-[200px]">
                <div class="md:col-span-2 md:row-span-2 bg-gradient-to-br from-indigo-600 to-violet-600 rounded-3xl p-8 text-white flex flex-col justify-between overflow-hidden relative">
                    <div class="absolute -top-10 -right-10 w-40 h-40 bg-white/10 rounded-full"></div>
                    <div class="relative"><div class="text-5xl mb-3">🚀</div><h3 class="text-2xl font-bold mb-2">Ship 10x faster</h3><p class="opacity-90">Pre-built blocks, smooth animations, and one-click templates get you from idea to live in minutes.</p></div>
                </div>
                <div class="bg-slate-900 rounded-3xl p-6 text-white flex flex-col justify-between"><div class="text-3xl">⚡</div><div><div class="text-3xl font-bold mb-1">99.9%</div><p class="text-sm text-slate-400">Guaranteed uptime SLA</p></div></div>
                <div class="bg-emerald-50 rounded-3xl p-6 flex flex-col justify-between"><div class="text-3xl">🌱</div><div><div class="text-3xl font-bold text-emerald-700 mb-1">100%</div><p class="text-sm text-emerald-700">Carbon neutral hosting</p></div></div>
                <div class="bg-amber-50 rounded-3xl p-6 flex flex-col justify-between md:col-span-2"><div class="text-3xl">🎨</div><div><h3 class="font-bold text-amber-900 mb-1">17+ professional themes</h3><p class="text-sm text-amber-800">Choose from handcrafted themes or build your own with the visual editor.</p></div></div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: STEP / PROCESS
    // =====================================================================
    {
        name: 'Process Steps',
        category: 'Content',
        icon: 'list',
        desc: 'Horizontal numbered process',
        html: `<div class="lumina-layout-block w-full max-w-6xl mx-auto py-16 px-6 font-sans">
            <div class="text-center mb-12"><h2 class="text-3xl font-bold text-slate-900 mb-3">How it works</h2><p class="text-slate-600">Three simple steps to launch your project.</p></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 relative">
                <div class="absolute top-8 left-0 right-0 h-0.5 bg-slate-200 hidden md:block -z-0"></div>
                <div class="text-center relative z-10"><div class="w-16 h-16 mx-auto bg-white border-2 border-indigo-600 rounded-full flex items-center justify-center text-2xl font-bold text-indigo-600 mb-4 shadow-lg">1</div><h3 class="font-bold text-slate-900 mb-2">Sign Up</h3><p class="text-sm text-slate-600">Create your free account in seconds. No credit card required.</p></div>
                <div class="text-center relative z-10"><div class="w-16 h-16 mx-auto bg-white border-2 border-emerald-600 rounded-full flex items-center justify-center text-2xl font-bold text-emerald-600 mb-4 shadow-lg">2</div><h3 class="font-bold text-slate-900 mb-2">Customize</h3><p class="text-sm text-slate-600">Pick a template, drag blocks, and make it yours.</p></div>
                <div class="text-center relative z-10"><div class="w-16 h-16 mx-auto bg-white border-2 border-pink-600 rounded-full flex items-center justify-center text-2xl font-bold text-pink-600 mb-4 shadow-lg">3</div><h3 class="font-bold text-slate-900 mb-2">Publish</h3><p class="text-sm text-slate-600">Go live with one click. Share with the world.</p></div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: NOTIFICATION / ALERT
    // =====================================================================
    {
        name: 'Alert Banner',
        category: 'Content',
        icon: 'bell',
        desc: 'Dismissible alert banner',
        html: `<div class="lumina-layout-block w-full max-w-5xl mx-auto my-6 px-6 font-sans">
            <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 flex items-start gap-3">
                <div class="text-2xl">💡</div>
                <div class="flex-1"><h4 class="font-bold text-indigo-900 mb-1">New: AI-powered content suggestions</h4><p class="text-sm text-indigo-700">We've added smart suggestions to help you write faster. <a href="#" class="font-semibold underline">Try it now →</a></p></div>
                <button class="text-indigo-400 hover:text-indigo-600 text-2xl leading-none">×</button>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: AVATAR STACK / SOCIAL
    // =====================================================================
    {
        name: 'Avatar Stack',
        category: 'Content',
        icon: 'users',
        desc: 'Social proof with stacked avatars',
        html: `<div class="lumina-layout-block w-full max-w-4xl mx-auto py-12 px-6 font-sans">
            <div class="flex flex-col md:flex-row items-center justify-center gap-6 text-center md:text-left">
                <div class="flex -space-x-3">
                    <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=80&q=80" alt="" class="w-12 h-12 rounded-full border-2 border-white shadow">
                    <img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=80&q=80" alt="" class="w-12 h-12 rounded-full border-2 border-white shadow">
                    <img src="https://images.unsplash.com/photo-1438761681033-6461ffad8d80?auto=format&fit=crop&w=80&q=80" alt="" class="w-12 h-12 rounded-full border-2 border-white shadow">
                    <img src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=80&q=80" alt="" class="w-12 h-12 rounded-full border-2 border-white shadow">
                    <div class="w-12 h-12 rounded-full border-2 border-white shadow bg-slate-900 text-white text-xs font-bold flex items-center justify-center">+8k</div>
                </div>
                <div><div class="flex text-yellow-400 mb-1 justify-center md:justify-start">★★★★★</div><p class="text-slate-700 font-medium">Joined by <strong>8,000+ creators</strong> worldwide</p></div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: METRIC / KPI CARD
    // =====================================================================
    {
        name: 'KPI Cards',
        category: 'Data',
        icon: 'bar-chart',
        desc: 'Dashboard-style metric cards',
        html: `<div class="lumina-layout-block w-full max-w-6xl mx-auto py-8 px-6 font-sans">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm">
                    <div class="flex items-center justify-between mb-2"><span class="text-sm text-slate-500">Total Revenue</span><span class="text-xs px-2 py-1 bg-emerald-50 text-emerald-700 rounded-full font-semibold">↑ 12%</span></div>
                    <div class="text-3xl font-bold text-slate-900">$48,295</div>
                    <div class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-gradient-to-r from-emerald-400 to-emerald-600" style="width: 72%"></div></div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm">
                    <div class="flex items-center justify-between mb-2"><span class="text-sm text-slate-500">Active Users</span><span class="text-xs px-2 py-1 bg-emerald-50 text-emerald-700 rounded-full font-semibold">↑ 8%</span></div>
                    <div class="text-3xl font-bold text-slate-900">12,847</div>
                    <div class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-gradient-to-r from-indigo-400 to-indigo-600" style="width: 64%"></div></div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm">
                    <div class="flex items-center justify-between mb-2"><span class="text-sm text-slate-500">Conversion Rate</span><span class="text-xs px-2 py-1 bg-rose-50 text-rose-700 rounded-full font-semibold">↓ 2%</span></div>
                    <div class="text-3xl font-bold text-slate-900">3.42%</div>
                    <div class="mt-4 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-gradient-to-r from-amber-400 to-rose-500" style="width: 34%"></div></div>
                </div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: GLASSMORPHIC CARD
    // =====================================================================
    {
        name: 'Glass Card',
        category: 'Content',
        icon: 'box',
        desc: 'Frosted glass effect on gradient background',
        html: `<div class="lumina-layout-block w-full max-w-4xl mx-auto my-12 px-6 font-sans">
            <div class="rounded-3xl p-12 relative overflow-hidden" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <div class="absolute inset-0" style="background-image: radial-gradient(circle at 30% 20%, rgba(255,255,255,0.2) 0%, transparent 50%);"></div>
                <div class="relative z-10 backdrop-blur-md bg-white/10 border border-white/20 rounded-2xl p-8 text-white">
                    <div class="text-4xl mb-4">✨</div>
                    <h3 class="text-2xl font-bold mb-3">Premium glassmorphism</h3>
                    <p class="opacity-90 mb-6 max-w-md">Frosted-glass effect layered on a vibrant gradient. Perfect for hero callouts or feature highlights.</p>
                    <a href="#" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white/20 backdrop-blur border border-white/30 rounded-full font-semibold hover:bg-white/30 transition">Learn more →</a>
                </div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: CODE SNIPPET (Terminal style)
    // =====================================================================
    {
        name: 'Code Snippet',
        category: 'Content',
        icon: 'code',
        desc: 'Terminal-style code display',
        html: `<div class="lumina-layout-block w-full max-w-3xl mx-auto my-8 px-6 font-sans">
            <div class="rounded-2xl overflow-hidden shadow-2xl bg-slate-900">
                <div class="flex items-center gap-2 px-4 py-3 bg-slate-800 border-b border-slate-700">
                    <div class="w-3 h-3 rounded-full bg-rose-500"></div>
                    <div class="w-3 h-3 rounded-full bg-amber-500"></div>
                    <div class="w-3 h-3 rounded-full bg-emerald-500"></div>
                    <span class="ml-3 text-xs text-slate-400 font-mono">install.sh</span>
                </div>
                <pre class="p-6 text-sm font-mono text-slate-300 leading-relaxed overflow-x-auto"><span class="text-emerald-400">$</span> npm install @lumina/editor
<span class="text-slate-500"># Installing packages...</span>
<span class="text-emerald-400">✓</span> installed 1 package in 2.3s

<span class="text-emerald-400">$</span> lumina init my-project
<span class="text-emerald-400">✓</span> Project created successfully!

<span class="text-emerald-400">$</span> lumina serve
🚀 Ready on <span class="text-indigo-400">http://localhost:3000</span></pre>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: BADGE / CHIP ROW
    // =====================================================================
    {
        name: 'Badge Row',
        category: 'Content',
        icon: 'tag',
        desc: 'Tag chips with hover effect',
        html: `<div class="lumina-layout-block w-full max-w-4xl mx-auto py-8 px-6 font-sans text-center">
            <p class="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-4">Popular tags</p>
            <div class="flex flex-wrap gap-2 justify-center">
                <a href="#" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-full text-sm font-medium transition">#design</a>
                <a href="#" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-full text-sm font-medium transition">#development</a>
                <a href="#" class="px-4 py-2 bg-indigo-100 hover:bg-indigo-200 text-indigo-700 rounded-full text-sm font-medium transition">#tailwind</a>
                <a href="#" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-full text-sm font-medium transition">#react</a>
                <a href="#" class="px-4 py-2 bg-emerald-100 hover:bg-emerald-200 text-emerald-700 rounded-full text-sm font-medium transition">#typescript</a>
                <a href="#" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-full text-sm font-medium transition">#css</a>
                <a href="#" class="px-4 py-2 bg-pink-100 hover:bg-pink-200 text-pink-700 rounded-full text-sm font-medium transition">#animations</a>
                <a href="#" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-full text-sm font-medium transition">#ui</a>
                <a href="#" class="px-4 py-2 bg-amber-100 hover:bg-amber-200 text-amber-700 rounded-full text-sm font-medium transition">#ux</a>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: PROFILE CARD
    // =====================================================================
    {
        name: 'Profile Card',
        category: 'Content',
        icon: 'user',
        desc: 'Social profile card with stats',
        html: `<div class="lumina-layout-block w-full max-w-sm mx-auto my-8 px-6 font-sans">
            <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-100">
                <div class="h-24 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500"></div>
                <div class="px-6 pb-6">
                    <div class="-mt-12 mb-4"><img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=160&q=80" alt="" class="w-24 h-24 rounded-full border-4 border-white shadow-lg"></div>
                    <h3 class="text-xl font-bold text-slate-900">Anna Petrova</h3>
                    <p class="text-sm text-slate-500 mb-1">Senior Product Designer</p>
                    <p class="text-sm text-slate-500 mb-4">📍 San Francisco, CA</p>
                    <p class="text-sm text-slate-600 mb-6 leading-relaxed">Designing delightful experiences for the web. Coffee enthusiast ☕</p>
                    <div class="grid grid-cols-3 gap-2 py-4 border-y border-slate-100 text-center mb-4">
                        <div><div class="font-bold text-slate-900">248</div><div class="text-xs text-slate-500">Posts</div></div>
                        <div><div class="font-bold text-slate-900">12.4k</div><div class="text-xs text-slate-500">Followers</div></div>
                        <div><div class="font-bold text-slate-900">389</div><div class="text-xs text-slate-500">Following</div></div>
                    </div>
                    <button class="w-full py-2.5 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition">Follow</button>
                </div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: MARQUEE / SCROLLING TEXT
    // =====================================================================
    {
        name: 'Marquee Strip',
        category: 'Content',
        icon: 'layout',
        desc: 'Horizontal scrolling text strip',
        html: `<div class="lumina-layout-block w-full overflow-hidden py-6 font-sans bg-slate-900 text-white">
            <div class="flex gap-8 whitespace-nowrap animate-pulse" style="animation: marquee 20s linear infinite;">
                <span class="text-2xl font-bold opacity-70">⚡ FAST</span><span class="text-2xl">•</span>
                <span class="text-2xl font-bold opacity-70">🎨 BEAUTIFUL</span><span class="text-2xl">•</span>
                <span class="text-2xl font-bold opacity-70">🔒 SECURE</span><span class="text-2xl">•</span>
                <span class="text-2xl font-bold opacity-70">📱 RESPONSIVE</span><span class="text-2xl">•</span>
                <span class="text-2xl font-bold opacity-70">🌍 GLOBAL</span><span class="text-2xl">•</span>
                <span class="text-2xl font-bold opacity-70">⚡ FAST</span><span class="text-2xl">•</span>
                <span class="text-2xl font-bold opacity-70">🎨 BEAUTIFUL</span><span class="text-2xl">•</span>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: EMPTY STATE
    // =====================================================================
    {
        name: 'Empty State',
        category: 'Content',
        icon: 'inbox',
        desc: 'Friendly empty state with CTA',
        html: `<div class="lumina-layout-block w-full max-w-md mx-auto py-16 px-6 font-sans text-center">
            <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-slate-100 flex items-center justify-center text-4xl">📭</div>
            <h3 class="text-xl font-bold text-slate-900 mb-2">No projects yet</h3>
            <p class="text-slate-600 mb-6">Get started by creating your first project. It only takes a minute.</p>
            <button class="px-6 py-3 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition inline-flex items-center gap-2">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                New Project
            </button>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: ANNOUNCEMENT / RELEASE NOTE
    // =====================================================================
    {
        name: 'Release Note',
        category: 'Content',
        icon: 'git-branch',
        desc: 'Version release announcement',
        html: `<div class="lumina-layout-block w-full max-w-3xl mx-auto my-8 px-6 font-sans">
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                <div class="bg-gradient-to-r from-emerald-500 to-teal-500 px-6 py-4 flex items-center justify-between text-white">
                    <div><span class="text-xs font-bold uppercase tracking-wider opacity-80">New Release</span><h3 class="text-xl font-bold">Version 3.0</h3></div>
                    <span class="px-3 py-1 bg-white/20 backdrop-blur rounded-full text-xs font-bold">LATEST</span>
                </div>
                <div class="p-6">
                    <p class="text-slate-600 mb-4">Major release with brand new features and performance improvements.</p>
                    <ul class="space-y-2 text-sm">
                        <li class="flex items-start gap-2"><span class="text-emerald-500 mt-0.5">✓</span><span class="text-slate-700"><strong>New:</strong> Bento grid blocks and glassmorphism cards.</span></li>
                        <li class="flex items-start gap-2"><span class="text-emerald-500 mt-0.5">✓</span><span class="text-slate-700"><strong>Improved:</strong> 40% faster page loads.</span></li>
                        <li class="flex items-start gap-2"><span class="text-emerald-500 mt-0.5">✓</span><span class="text-slate-700"><strong>Fixed:</strong> Animation rendering on Safari.</span></li>
                    </ul>
                    <div class="mt-6 flex gap-3"><a href="#" class="px-4 py-2 bg-slate-900 text-white text-sm rounded-lg hover:bg-slate-800 transition">View Changelog</a><a href="#" class="px-4 py-2 border border-slate-200 text-slate-700 text-sm rounded-lg hover:bg-slate-50 transition">Download</a></div>
                </div>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: COMPARISON TABLE
    // =====================================================================
    {
        name: 'Comparison Table',
        category: 'Content',
        icon: 'columns',
        desc: 'Feature comparison matrix',
        html: `<div class="lumina-layout-block w-full max-w-4xl mx-auto py-12 px-6 font-sans">
            <h2 class="text-2xl font-bold text-slate-900 mb-8 text-center">Compare plans</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200">
                <table class="w-full text-sm">
                    <thead><tr class="bg-slate-50"><th class="py-4 px-4 text-left font-semibold text-slate-700">Feature</th><th class="py-4 px-4 text-center font-semibold text-slate-700">Free</th><th class="py-4 px-4 text-center font-semibold text-indigo-700">Pro</th><th class="py-4 px-4 text-center font-semibold text-slate-700">Enterprise</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr><td class="py-3 px-4 font-medium text-slate-900">Projects</td><td class="text-center text-slate-500">3</td><td class="text-center text-slate-900 font-semibold">Unlimited</td><td class="text-center text-slate-900 font-semibold">Unlimited</td></tr>
                        <tr><td class="py-3 px-4 font-medium text-slate-900">Storage</td><td class="text-center text-slate-500">1 GB</td><td class="text-center text-slate-900 font-semibold">100 GB</td><td class="text-center text-slate-900 font-semibold">∞</td></tr>
                        <tr><td class="py-3 px-4 font-medium text-slate-900">Custom Domain</td><td class="text-center text-rose-400">✕</td><td class="text-center text-emerald-500">✓</td><td class="text-center text-emerald-500">✓</td></tr>
                        <tr><td class="py-3 px-4 font-medium text-slate-900">Analytics</td><td class="text-center text-rose-400">✕</td><td class="text-center text-emerald-500">✓</td><td class="text-center text-emerald-500">✓</td></tr>
                        <tr><td class="py-3 px-4 font-medium text-slate-900">Priority Support</td><td class="text-center text-rose-400">✕</td><td class="text-center text-rose-400">✕</td><td class="text-center text-emerald-500">✓</td></tr>
                        <tr><td class="py-3 px-4 font-medium text-slate-900">SLA</td><td class="text-center text-rose-400">✕</td><td class="text-center text-rose-400">✕</td><td class="text-center text-emerald-500">99.99%</td></tr>
                    </tbody>
                </table>
            </div>
        </div><p><br/></p>`
    },

    // =====================================================================
    // NEW: FEATURED TESTIMONIAL (large single)
    // =====================================================================
    {
        name: 'Featured Testimonial',
        category: 'Marketing',
        icon: 'message-square',
        desc: 'Big single testimonial with author photo',
        html: `<div class="lumina-layout-block w-full max-w-4xl mx-auto py-16 px-6 font-sans">
            <div class="bg-gradient-to-br from-slate-900 to-indigo-900 rounded-3xl p-8 md:p-12 text-white relative overflow-hidden">
                <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/20 rounded-full blur-3xl"></div>
                <div class="relative z-10 grid grid-cols-1 md:grid-cols-[1fr_2fr] gap-8 items-center">
                    <div class="text-center"><img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=300&q=80" alt="" class="w-32 h-32 rounded-full mx-auto border-4 border-white/20 shadow-2xl"><div class="mt-4"><div class="font-bold text-lg">Marcus Reed</div><div class="text-sm text-slate-300">CTO, TechCorp</div></div></div>
                    <div>
                        <div class="text-yellow-400 text-2xl mb-4">★★★★★</div>
                        <blockquote class="text-xl md:text-2xl font-medium leading-relaxed mb-4">"Lumina completely transformed how our team ships products. What used to take weeks now takes hours. The ROI has been nothing short of remarkable."</blockquote>
                        <div class="flex items-center gap-4 text-sm text-slate-300"><span>📈 4x faster delivery</span><span>·</span><span>💰 60% cost savings</span></div>
                    </div>
                </div>
            </div>
        </div><p><br/></p>`
    }
];
