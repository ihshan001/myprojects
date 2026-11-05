import SwiftUI

struct CardInfoForm: View {
    
    @Binding var nameOnCard: String
    @Binding var cardNumber: String
    @Binding var cvv: String
    @Binding var expirationDate: String
    
    
    
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            
            Text("Card Info")
                .font(.system(size: 20, weight: .bold))
            
            InputField(
                placeholder: "Name on the Card",
                value: $nameOnCard
            )
            
            InputField(
                placeholder: "Card Number",
                value: $cardNumber
            )
            
            HStack(spacing: 12) {
                InputField(
                    placeholder: "CVV",
                    value: $cvv
                )
                .frame(maxWidth: 100)
                
                InputField(
                    placeholder: "Expiration Date (YY/MM)",
                    value: $expirationDate
                )
                .frame(maxWidth: .infinity)
            }
        }
        .padding(.horizontal, 16)
    }
}

struct CardInfoForm_Previews: PreviewProvider {
    @State static var name = ""
    @State static var number = ""
    @State static var cvv = ""
    @State static var exp = ""
    
    static var previews: some View {
        CardInfoForm(
            nameOnCard: $name,
            cardNumber: $number,
            cvv: $cvv,
            expirationDate: $exp
        )
        .previewLayout(.sizeThatFits)
    }
}
