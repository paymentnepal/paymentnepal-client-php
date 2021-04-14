API client for Paymentnepal
=============

API client implements two basic classes PaymentnepalService and PaymentnepalCallback to inherit.

PaymentnepalService - service in Paymentnepal billing. Allows getting payment methods available, init transaction, get transaction info. A single instance is required for each service existing.

PaymentnepalCallback - callback handler for Paymentnepal. Checks sign and calls method relevant to "command" value.

During the work a PaymentnepalException instance may be raised.

Example of using the client to init transaction:

       $service = new PaymentnepalService(<service-id>, '<service-secret>');
       try {
           $service->initPayment('spg', 10, 'Test', 'test@example.com', '71111111111');
       } catch (PaymentnepalException $e) {
           echo $e->getMessage();
       }

Exapmle of using the clinet to handle callback:

       class MyPaymentnepalCallback extends PaymentnepalCallback {

           public function callbackSuccess($data) {
               // settling successful transaction
           }
       }

       $service1 = new PaymentnepalService(<service1-id>, '<service1-secret>');
       $service2 = new PaymentnepalService(<service2-id>, '<service2-secret>');
       $callback = new MyPaymentnepalCallback(array($service1, $service2]));
       $callback->handle(<POST-data-array>)
       
       
       
Example of using the client to make recurrent payments:

       $service = new PaymentnepalService(<service-id>, '<service-secret>');
       
       try {
            // Getting card token. 
            // Only available for services than have such option
            // All other services must init payment without background API
            $token = $service->createCardToken(
                '4300000000000777', 11, '19', '123', True
            );
            echo "Card token: " . $token;

        } catch (PaymentnepalException $e) {
            echo $e->getMessage();
        }

        try {
            // Init first recurrent payment
            $first_order_id = 'first-' . uniqid();
            $recurrent_params = RecurrentParams::first_pay(
                // Link to the rules and terms of recurrent payment
                'http://example.com/rules', 
                 // Description of recurrent payment purpose	
                'Test'
            );
            $service->initPayment(
                 'spg_test',
                 10,
                 'Test',
                 'test@example.com',
                 '71111111111',
                 $first_order_id, 
                 'partner',
                 $token,
                 $recurrent_params
            );
         } catch (PaymentnepalException $e) {
            echo $e->getMessage();
         }

         try {
            // Init second and further recurrent payment
            $recurrent_params = RecurrentParams::next_pay(
                // order_id of first recurrent payment
                $first_order_id  
            );
            $service->initPayment(
                'spg_test',
                10,
                'Test',
                'test@example.com',
                '71111111111',
                uniqid(),  // order_id of current payment
                'partner',
                $token,
                $recurrent_params
            );
          } catch (PaymentnepalException $e) {
            echo $e->getMessage();
          }


