console.log(cbpData)

clover = function () {
    const clover = new Clover(cbpData.apiAccessKey);

    console.log('clovered')

    const elements = clover.elements();

    const styles = cbpData.apiStyles.replace(/[\r\n]+/g, '') ? JSON.parse(cbpData.apiStyles.replace(/[\r\n]+/g, '')) : {
        "input": {
            width: "100%",
            height: "40px",
            "font-size": "20px",
            border: "1px solid #cccccc",
            padding: "16px 12px",
            "border-radius": "10px",
        },
    };


    const cardNumber = elements.create("CARD_NUMBER", styles);
    const cardDate = elements.create("CARD_DATE", styles);
    const cardCvv = elements.create("CARD_CVV", styles);
    const cardPostalCode = elements.create("CARD_POSTAL_CODE", styles);
    //const card = elements.create('CARD_NAME', styles);

    // card.mount("#card-name");
    cardNumber.mount("#cbp-clover-card-number");
    cardDate.mount("#cbp-clover-card-date");
    cardCvv.mount("#cbp-clover-card-cvv");
    cardPostalCode.mount("#cbp-clover-card-postal-code");

    const cardResponse = document.getElementById("cbp-card-response");
    const displayCardNumberError = document.getElementById("card-number-errors");
    const displayCardDateError = document.getElementById("card-date-errors");
    const displayCardCvvError = document.getElementById("card-cvv-errors");
    const displayCardPostalCodeError = document.getElementById(
        "card-postal-code-errors"
    );

    const currencyInput = document.getElementById('cbp-amount-field');

    if (currencyInput) {
        currencyInput.addEventListener('input', function () {
            const inputVal = this.value.replace(/[^0-9\.]/g, '');

            if (!isNaN(inputVal)) {
                const currencyVal = '$' + parseFloat(inputVal).toFixed(2).replace(/(\d)(?=(\d{3})+\.)/g, '$1,');
                this.value = currencyVal;
            } else {
                this.value = '';
            }
        });
    }


    var phoneInput = document.getElementById('cbp-phone-field');

    if (phoneInput) {
        phoneInput.addEventListener('input', function () {
            // Remove all non-numeric characters from the input
            var inputVal = this.value.replace(/[^0-9]/g, '');

            // Format the input as a phone number
            var formattedVal = '(' + inputVal.substring(0, 3) + ') ' + inputVal.substring(3, 6) + '-' + inputVal.substring(6, 10);

            // Update the input value with the formatted phone number
            this.value = formattedVal;
        });
    }

    const form = document.getElementById("cbp-payment-form");
    const submitButton = document.getElementById("cbp-submit");
    const displayError = document.getElementById("cbp-card-error");

    const replaceError = (error) => displayError.innerHTML = displayError.innerHTML.replace(/%error/g, match => {
        if (match === '%error') {
            return error;
        }
    });



    function cloverTokenHandler(token) {

        if (!token) {
            replaceError("Sorry, our payment system has not been configured yet.");
            displayError.style.display = 'flex';
            return false;
        }

        const inputs = Array.from(document.querySelectorAll('#cbp-payment-form input, #cbp-payment-form select'));

        const data = inputs.reduce((obj, input) => {
            obj[input.name] = input.value;
            return obj;
        }, {});

        data.source = token;
        data.action = 'cbp_submitted';

        const xhr = new XMLHttpRequest();
        const url = `/wp-admin/admin-ajax.php?action=${data.action}`;
        const json = JSON.stringify(data);

        xhr.open("POST", url, true);
        xhr.setRequestHeader("Content-Type", "application/json");

        xhr.onreadystatechange = () => {
            setTimeout(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = cbpData.buttonText
                submitButton.classList.remove("cbp-button-loading");
            }, 500);

            if (xhr.readyState === 4 && xhr.status === 200) {
                console.log(JSON.parse(xhr.responseText));

                const response = JSON.parse(xhr.responseText);

                if (!response.success) {
                    replaceError(response.data.error.message);
                    displayError.style.display = 'flex';
                } else {
                    const amount = ((response.data.amount || 0) / 100).toFixed(2)

                    const link = cbpData.apiProduction ? `https://clover.com/p/${response.data.id}` : `https://sandbox.dev.clover.com/p/${response.data.id}`

                    const replaceIt = (div) =>
                        div.innerHTML = div.innerHTML.replace(/%id|%amount|%link/g, match => {
                            if (match === '%id') {
                                return response.data.id;
                            } else if (match === '%amount') {
                                return amount;
                            } else if (match === '%link') {
                                return link;
                            }
                        });

                    replaceIt(cardResponse);

                    if (!cbpData.onPay || cbpData.onPay == "0") {
                        cardResponse.style.display = 'flex';
                    } else if (cbpData.onPay == '1') {
                        cardResponse.style.display = 'flex';
                        form.insertBefore(cardResponse, form.firstElementChild);

                        for (let i = 0; i < form.children.length; i++) {
                            const child = form.children[i];
                            if (child !== cardResponse) {
                                child.style.display = 'none';
                            }
                        }
                    } else if (cbpData.onPay == '2') {
                        window.location.href = cbpData.onPayValue;
                    } else if (cbpData.onPay == '3') {
                        const userDiv = document.getElementById(cbpData.onPayValue);

                        if (userDiv) {
                            userDiv.style.display = 'flex';
                            replaceIt(userDiv);
                        }

                        for (let i = 0; i < form.children.length; i++) {
                            const child = form.children[i];
                            if (child !== cardResponse) {
                                child.style.display = 'none';
                            }
                        }
                    }
                }
            }
        };

        xhr.send(json);
    }

    const process = () => {
        displayCardCvvError.innerHTML = ''
        displayCardDateError.innerHTML = ''
        displayCardNumberError.innerHTML = ''
        displayCardPostalCodeError.innerHTML = ''

        clover.createToken().then(function (result) {
            console.log({ result })

            if (result.errors) {
                if (result.errors.CARD_CVV) {
                    displayCardCvvError.innerHTML = result.errors.CARD_CVV
                }
                if (result.errors.CARD_DATE) {
                    displayCardDateError.innerHTML = result.errors.CARD_DATE
                }

                if (result.errors.CARD_NUMBER) {
                    displayCardNumberError.innerHTML = result.errors.CARD_NUMBER
                }

                if (result.errors.CARD_POSTAL_CODE) {
                    displayCardPostalCodeError.innerHTML = result.errors.CARD_POSTAL_CODE
                }

                setTimeout(function () {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.classList.remove("cbp-button-loading");
                        submitButton.innerHTML = cbpData.buttonText
                    }
                }, 500);

                return false;
            } else {
                cloverTokenHandler(result.token);
                return true;
            }
        });
    }

    jQuery(function ($) {

        var checkout_form = $('form.checkout');
        

        checkout_form.on('checkout_place_order', () => {
            console.log(('Processing...'))
            var inputElement = document.getElementById("cbp-clover-token");
            var uuid = document.getElementById("cbp-clover-uuid");

            uuid.value = cbpData.uuid;

            if(inputElement.value) return true;

            clover.createToken().then(function (result) {
                console.log({ result })
    
                if (result.errors) {
                    if (result.errors.CARD_CVV) {
                        displayCardCvvError.innerHTML = result.errors.CARD_CVV
                    }
                    if (result.errors.CARD_DATE) {
                        displayCardDateError.innerHTML = result.errors.CARD_DATE
                    }
    
                    if (result.errors.CARD_NUMBER) {
                        displayCardNumberError.innerHTML = result.errors.CARD_NUMBER
                    }
    
                    if (result.errors.CARD_POSTAL_CODE) {
                        displayCardPostalCodeError.innerHTML = result.errors.CARD_POSTAL_CODE
                    }
    
                    
                    
                } else {
                  
                    // set a new value for the input element
                    inputElement.value = result.token
                    $( 'form.checkout' ).submit();
                }
            });
 return false;
        });

       

    });

    if (form) {
        form.addEventListener("submit", function (event) {
            event.preventDefault();

            if (submitButton.disabled) {
                return;
            }

            displayError.style.display = 'none';
            cardResponse.style.display = 'none';



            if (cbpData.onPay == '3') {
                const userDiv = document.getElementById(cbpData.onPayValue);

                if (userDiv) {
                    userDiv.style.display = 'none';
                }
            }

            submitButton.disabled = true;

            submitButton.classList.add("cbp-button-loading");
            submitButton.innerHTML = cbpData.submittedText


            process();
        });
    }
}