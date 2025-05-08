<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment for {{ $team->name }}</title>
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        #payment-element { margin-bottom: 20px; }
        button { padding: 10px 15px; font-size: 16px; cursor: pointer; }
        #error-message { color: red; margin-top: 10px; }
        .spinner { /* Basic loading spinner styles */ }
    </style>
</head>
<body>
    <h1>Complete Payment</h1>
    <p>Unlock PDF features for team: <strong>{{ $team->name }}</strong></p>
    <p>Amount: <strong>{{ number_format($amount / 100, 2) }} {{ strtoupper($currency) }}</strong></p>

    <form id="payment-form">
        <div id="payment-element">
            <!-- Stripe Payment Element will be inserted here -->
        </div>
        <button id="submit" type="submit">
            <span id="button-text">Pay Now</span>
            <span id="spinner" style="display: none;">Processing...</span>
        </button>
        <div id="error-message">
            <!-- Stripe error messages will be displayed here -->
        </div>
    </form>

    <script>
        console.log('Script loaded.'); // Check if script runs

        const stripeKey = "{{ $stripeKey ?? '' }}"; // Add default empty string
        const clientSecret = "{{ $clientSecret ?? '' }}";
        const returnUrl = "{{ $returnUrl ?? '' }}";

        console.log('Stripe Key:', stripeKey ? 'Loaded' : 'MISSING!');
        console.log('Client Secret:', clientSecret ? 'Loaded' : 'MISSING!');
        console.log('Return URL:', returnUrl ? returnUrl : 'MISSING!');


        const form = document.getElementById('payment-form');
        const submitButton = document.getElementById('submit');
        const errorMessage = document.getElementById('error-message');
        const buttonText = document.getElementById('button-text');
        const spinner = document.getElementById('spinner');

        if (!stripeKey || !clientSecret || !returnUrl) {
            errorMessage.textContent = 'Payment configuration missing. Cannot proceed.';
            if(submitButton) submitButton.disabled = true; // Disable if exists
            console.error('Configuration missing, disabling form.');
        } else {
            try {
                const stripe = Stripe(stripeKey);
                console.log('Stripe object initialized.');

                const options = { clientSecret: clientSecret };
                const elements = stripe.elements(options);
                console.log('Stripe elements created.');

                const paymentElement = elements.create('payment');
                console.log('Payment Element created.');

                // Ensure the mount point exists before mounting
                const mountPoint = document.getElementById('payment-element');
                if (mountPoint) {
                    paymentElement.mount('#payment-element');
                    console.log('Payment Element mounted.');
                } else {
                     console.error('Mount point #payment-element not found!');
                     errorMessage.textContent = 'Payment form could not be displayed correctly.';
                     if(submitButton) submitButton.disabled = true;
                }


                if (form) {
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        console.log('Form submitted!'); // DEBUG
                        setLoading(true);
                        errorMessage.textContent = '';

                        console.log('Calling stripe.confirmPayment...'); // DEBUG
                        const { error } = await stripe.confirmPayment({
                            elements,
                            confirmParams: {
                                return_url: returnUrl,
                            },
                        });

                        if (error) {
                            console.error('Stripe confirmPayment error:', error); // DEBUG
                            errorMessage.textContent = error.message;
                            setLoading(false);
                        } else {
                            // Should redirect, this part might not be reached if successful redirect happens
                            console.log('Stripe confirmPayment successful (or redirecting)...'); // DEBUG
                            buttonText.textContent = 'Redirecting...';
                            // Keep button disabled during redirect
                        }
                    });
                    console.log('Submit event listener attached.'); // DEBUG
                } else {
                     console.error('Form #payment-form not found!');
                     errorMessage.textContent = 'Payment form not found.';
                }
            } catch (e) {
                console.error('Error initializing Stripe:', e);
                errorMessage.textContent = 'Could not initialize payment form. ' + e.message;
                 if(submitButton) submitButton.disabled = true;
            }
        }

         function setLoading(isLoading) {
             console.log('Setting loading:', isLoading); // DEBUG
             if(!submitButton) return; // Safety check

             if (isLoading) {
                 submitButton.disabled = true;
                 if(spinner) spinner.style.display = 'inline';
                 if(buttonText) buttonText.style.display = 'none';
             } else {
                 submitButton.disabled = false;
                 if(spinner) spinner.style.display = 'none';
                 if(buttonText) buttonText.style.display = 'inline';
             }
         }
    </script>
</body>
</html>
