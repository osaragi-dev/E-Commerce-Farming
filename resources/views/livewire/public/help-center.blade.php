<div>
    <section class="py-8 md:py-12">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <span class="breadcrumb-current" aria-current="page">Help</span>
            </nav>
        </div>
    </section>

    <section class="pb-12 md:pb-20">
        <div class="wrap">
            <div class="max-w-2xl">
                <p class="eyebrow">A little guidance</p>
                <h1 class="page-heading mt-3">How can we help?</h1>
                <p class="page-description mt-4">Everything you need to choose your products, place an order, and enjoy something fresh.</p>
            </div>

            <div class="help-grid mt-10">
                <article class="help-card">
                    <p class="eyebrow">01 / Choose</p>
                    <h2>How to Shop</h2>
                    <p>Browse our products, choose an available variant and quantity, then add them to your cart. Sign in to continue to checkout.</p>
                    <a href="{{ route('catalog') }}" class="text-link">Explore products <span aria-hidden="true">&rarr;</span></a>
                </article>

                <article id="delivery" class="help-card scroll-mt-24">
                    <p class="eyebrow">02 / Receive</p>
                    <h2>Delivery &amp; Pickup</h2>
                    <p>Choose an available delivery or pickup option at checkout. Confirm your contact details and location before placing your order.</p>
                    <p>Your shipping cost is shown in the order summary.</p>
                </article>

                <article id="payments" class="help-card scroll-mt-24">
                    <p class="eyebrow">03 / Pay</p>
                    <h2>Payment Methods</h2>
                    <p>Choose from the payment methods available at checkout. Follow the QRIS instructions and upload payment proof when requested, or choose cash.</p>
                    <p>Payment updates are shown in your order details.</p>
                </article>
            </div>

            <section class="help-faq mt-12 md:mt-20" aria-labelledby="help-faq-title">
                <div>
                    <p class="eyebrow">Good to know</p>
                    <h2 id="help-faq-title" class="page-heading mt-3">A few common questions.</h2>
                </div>
                <div class="faq-list">
                    <details class="faq-item">
                        <summary>Where can I follow my order?</summary>
                        <p>Sign in and open My Orders to see your order status. Open an order for its products, delivery or pickup details, and payment updates.</p>
                    </details>
                    <details class="faq-item">
                        <summary>What if my payment proof is rejected?</summary>
                        <p>Open the order details to read the reason and check the resubmission deadline. Upload a new image before that deadline.</p>
                    </details>
                    <details class="faq-item">
                        <summary>Can I cancel an order?</summary>
                        <p>If cancellation is available, a Cancel Order button appears in the order details. The option is no longer available once packing has started.</p>
                    </details>
                    <details class="faq-item">
                        <summary>How do I update my contact details?</summary>
                        <p>Open My Account and select Edit to update your name, phone number, email, address, or profile photo.</p>
                    </details>
                </div>
            </section>

            <div class="help-contact mt-12">
                <div>
                    <h2 class="text-2xl">Still need a hand?</h2>
                    <p class="page-description mt-2">Find our contact details and farm location.</p>
                </div>
                <a href="{{ route('company-profile.show') }}#contact" class="btn btn-primary">Contact the Farm</a>
            </div>
        </div>
    </section>
</div>
