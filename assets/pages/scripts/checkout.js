var Checkout = function () {

    function initIndianPincodeAutofill() {
        var pincodeInput = document.getElementById('post-code');
        var cityInput = document.getElementById('city');
        var stateInput = document.getElementById('region-state');
        var countryInput = document.getElementById('country');

        // Guard: checkout fields may not exist on every page template.
        if (!pincodeInput || !cityInput || !stateInput || !countryInput) {
            return;
        }

        var requestSeq = 0;
        var debounceTimer = null;

        var feedback = document.createElement('div');
        feedback.className = 'help-block';
        feedback.style.marginTop = '6px';
        pincodeInput.parentNode.appendChild(feedback);

        function setFeedback(type, message) {
            if (!message) {
                feedback.textContent = '';
                feedback.style.color = '';
                return;
            }

            feedback.innerHTML = message;
            if (type === 'error') {
                feedback.style.color = '#a94442';
            } else if (type === 'success') {
                feedback.style.color = '#3c763d';
            } else {
                feedback.style.color = '#31708f';
            }
        }

        function setReadonlyAfterAutofill(isReadonly) {
            stateInput.readOnly = isReadonly;
            countryInput.readOnly = isReadonly;
        }

        function isValidIndianPincode(pin) {
            return /^[0-9]{6}$/.test(pin);
        }

        function applyAutofill(postOffice) {
            cityInput.value = postOffice.District || cityInput.value || '';
            stateInput.value = postOffice.State || '';
            countryInput.value = postOffice.Country || 'India';
            setReadonlyAfterAutofill(true);
            setFeedback('success', 'Address details auto-filled from pincode.');
        }

        function fetchPincodeDetails(pin) {
            requestSeq += 1;
            var currentSeq = requestSeq;
            setFeedback('info', '<i class="fa fa-spinner fa-spin"></i> Fetching pincode details...');

            fetch('https://api.postalpincode.in/pincode/' + encodeURIComponent(pin), {
                method: 'GET'
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Network response was not OK');
                    }
                    return response.json();
                })
                .then(function (data) {
                    // Ignore stale response if user typed a new pincode while this was in-flight.
                    if (currentSeq !== requestSeq) {
                        return;
                    }

                    if (!Array.isArray(data) || !data.length) {
                        throw new Error('Unexpected API response format');
                    }

                    var result = data[0] || {};
                    var offices = Array.isArray(result.PostOffice) ? result.PostOffice : [];
                    if (result.Status !== 'Success' || !offices.length) {
                        setReadonlyAfterAutofill(false);
                        setFeedback('error', 'Invalid pincode. Please enter a valid 6-digit Indian pincode.');
                        return;
                    }

                    applyAutofill(offices[0]);
                })
                .catch(function () {
                    if (currentSeq !== requestSeq) {
                        return;
                    }
                    setReadonlyAfterAutofill(false);
                    setFeedback('error', 'Could not fetch pincode details right now. Please try again.');
                });
        }

        function handlePincodeLookup() {
            var pin = String(pincodeInput.value || '').trim();

            if (pin.length === 0) {
                setFeedback('', '');
                setReadonlyAfterAutofill(false);
                return;
            }

            if (!isValidIndianPincode(pin)) {
                setReadonlyAfterAutofill(false);
                setFeedback('error', 'Please enter a valid 6-digit Indian pincode.');
                return;
            }

            fetchPincodeDetails(pin);
        }

        // Trigger API lookup when user pauses typing.
        pincodeInput.addEventListener('input', function () {
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            debounceTimer = setTimeout(handlePincodeLookup, 450);
        });

        // Also trigger on blur for keyboard/tab users.
        pincodeInput.addEventListener('blur', handlePincodeLookup);
    }

    return {
        init: function () {
            
            $('#checkout').on('change', '#checkout-content input[name="account"]', function() {

              var title = '';

              if ($(this).attr('value') == 'register') {
                title = 'Step 2: Account &amp; Billing Details';
              } else {
                title = 'Step 2: Billing Details';
              }    

              $('#payment-address .accordion-toggle').html(title);
            });

            initIndianPincodeAutofill();

        }
    };

}();
