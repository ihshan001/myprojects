import SwiftUI


struct NotificationItem: Identifiable {
    let id = UUID()
    let title: String
    let description: String
    let date: String
    let time: String
    let avatarType: AvatarType
}

enum AvatarType {
    case personMale
    case bloodDrop
    case pills
    case personFemale
}


let sampleNotifications: [(section: String, items: [NotificationItem])] = [
    (
        section: "Today",
        items: [
            NotificationItem(
                title: "Scheduled Appointment",
                description: "lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
                date: "2026/02/16",
                time: "12:13PM",
                avatarType: .personMale
            ),
            NotificationItem(
                title: "Scheduled Appointment",
                description: "lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
                date: "2026/02/16",
                time: "12:13PM",
                avatarType: .bloodDrop
            )
        ]
    ),
    (
        section: "Yesterday",
        items: [
            NotificationItem(
                title: "Scheduled Appointment",
                description: "lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
                date: "2026/02/16",
                time: "12:13PM",
                avatarType: .pills
            )
        ]
    ),
    (
        section: "Oct 17",
        items: [
            NotificationItem(
                title: "Scheduled Appointment",
                description: "lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
                date: "2026/02/16",
                time: "12:13PM",
                avatarType: .personFemale
            )
        ]
    )
]



struct NotificationAvatarView: View {
    let avatarType: AvatarType

    var body: some View {
        ZStack {
            Circle()
                .fill(backgroundColor)
                .frame(width: 48, height: 48)

            avatarContent
        }
    }

    private var backgroundColor: Color {
        switch avatarType {
        case .personMale:   return Color(red: 0.85, green: 0.90, blue: 0.95)
        case .bloodDrop:    return Color(red: 1.0, green: 0.88, blue: 0.88)
        case .pills:        return Color(red: 0.88, green: 0.95, blue: 1.0)
        case .personFemale: return Color(red: 0.90, green: 0.88, blue: 0.95)
        }
    }

    @ViewBuilder
    private var avatarContent: some View {
        switch avatarType {
        case .personMale:
            Image(systemName: "person.fill")
                .resizable()
                .scaledToFit()
                .frame(width: 28, height: 28)
                .foregroundColor(Color(red: 0.35, green: 0.50, blue: 0.65))
                .offset(y: 4)

        case .bloodDrop:
            ZStack {
                Image(systemName: "drop.fill")
                    .resizable()
                    .scaledToFit()
                    .frame(width: 26, height: 26)
                    .foregroundColor(Color(red: 0.90, green: 0.25, blue: 0.25))

                Text("Rh")
                    .font(.system(size: 8, weight: .bold))
                    .foregroundColor(.white)
                    .offset(y: -1)
            }

        case .pills:
            Image(systemName: "pills.fill")
                .resizable()
                .scaledToFit()
                .frame(width: 28, height: 28)
                .foregroundColor(Color(red: 0.20, green: 0.60, blue: 0.85))

        case .personFemale:
            Image(systemName: "person.fill")
                .resizable()
                .scaledToFit()
                .frame(width: 28, height: 28)
                .foregroundColor(Color(red: 0.60, green: 0.45, blue: 0.70))
                .offset(y: 4)
        }
    }
}



struct NotificationCardView: View {
    let item: NotificationItem

    var body: some View {
        HStack(alignment: .top, spacing: 14) {
            NotificationAvatarView(avatarType: item.avatarType)

            VStack(alignment: .leading, spacing: 6) {
                Text(item.title)
                    .font(.system(size: 16, weight: .bold))
                    .foregroundColor(.black)

                Text(item.description)
                    .font(.system(size: 12))
                    .foregroundColor(Color(white: 0.45))
                    .lineLimit(3)
                    .fixedSize(horizontal: false, vertical: true)

                HStack(spacing: 4) {
                    Text("Date: \(item.date)")
                        .font(.system(size: 11))
                        .foregroundColor(Color(white: 0.60))

                    Text("approx: \(item.time)")
                        .font(.system(size: 11))
                        .foregroundColor(Color(white: 0.60))
                }
            }

            Spacer()
        }
        .padding(16)
        .background(
            RoundedRectangle(cornerRadius: 16)
                .fill(Color.white)
                .shadow(color: Color.black.opacity(0.06), radius: 8, x: 0, y: 2)
        )
    }
}



struct SectionHeaderView: View {
    let title: String

    var body: some View {
        HStack {
            Text(title)
                .font(.system(size: 20, weight: .bold))
                .foregroundColor(.black)
            Spacer()
        }
        .padding(.top, 8)
    }
}



struct NotificationView: View {
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        VStack(spacing: 0) {

       
            ZStack {
            
                Text("My Notifications")
                    .font(.system(size: 20, weight: .bold))
                    .foregroundColor(.black)
            }
            .padding(.horizontal, 20)
            .padding(.vertical, 16)
            .background(Color.white)

            Divider()
                .opacity(0.3)

     
            ScrollView(.vertical, showsIndicators: false) {
                VStack(alignment: .leading, spacing: 0) {
                    ForEach(sampleNotifications, id: \.section) { group in
                        SectionHeaderView(title: group.section)
                            .padding(.horizontal, 20)
                            .padding(.top, 20)
                            .padding(.bottom, 10)

                        VStack(spacing: 12) {
                            ForEach(group.items) { item in
                                NotificationCardView(item: item)
                            }
                        }
                        .padding(.horizontal, 20)
                    }
                }
                .padding(.bottom, 30)
            }
            .background(Color(red: 0.97, green: 0.97, blue: 0.98))
        }
        .background(Color.white)
//        .navigationBarHidden(true)
    }
}



struct NotificationView_Previews: PreviewProvider {
    static var previews: some View {
        NotificationView()
            .previewDevice(PreviewDevice(rawValue: "iPhone 15 Pro"))
            .previewDisplayName("Keshana - Notification")
    }
}
